# Jev Tactical Layer (experimental)

Split an AI NPC's autonomy into three timescales and give each to the system that is good at it:

| Layer     | System                              | Timescale     | Job                                                        |
|-----------|-------------------------------------|---------------|------------------------------------------------------------|
| Strategic | Main LLM (GPT, Claude, local model) | minutes       | personality, dialogue, relationships, plans, **goals**     |
| Tactical  | Jev (TypeSafe System One model)     | ~1 s per tick | **which CHIM action to run next**, target, interrupt, threat |
| Motor     | Skyrim (Papyrus / SKSE DLL)         | frames        | pathfinding, animation, combat mechanics                   |

The main model stops paying frontier-model prices to decide whether Lydia should walk ten feet
to the left. It hands over a *standing goal* ("guard the doorway while the player searches the
room, don't start fights") and goes back to sleep. Jev then answers, every tick, one bounded
question: *which of these concrete actions, with which of these concrete targets, best serves
the goal right now?* Skyrim executes it. The main model is only woken when Jev says the moment
needs real thought, when confidence stays low, or when the player talks.

Jev cannot invent an action or a target that is not in the list it is given, which is exactly the
property CHIM's action layer wants. It can still pick the wrong *valid* one, so the layer only
acts above a confidence threshold and otherwise waits.

## Status

Prototype, off by default. It runs entirely inside the server plugin API (`ext/`), needs no
change to the Skyrim mod, and falls back to the normal LLM flow on any error. The pure decision
logic is unit tested (`unittests/tests/JevTacticalTest.php`); the in-game loop has **not** been
played through yet. See "Known gaps" before relying on it.

## How it plugs into CHIM

```
player / game event
        │
        ▼
main.php ── prerequest hook ──► ext/jev_tactical/prerequest.php
                                   │  standing goal for this NPC?  no ──► normal LLM flow
                                   │  yes
                                   ▼
                     collect situation (nearby actors, hostiles, items,
                     inventory, spells, stats, recent events)  ← CHIM data functions
                                   ▼
                     build catalog: enabled CHIM actions × concrete candidates
                                   ▼
                     one Jev request, speculative fan-out:
                       action        choice   Attack | Follow | ... | Wait | Escalate
                       Attack.target choice   Bandit Marauder | none
                       MoveTo.target choice   Dragonborn | Bandit | Riverwood | none
                       CastSpell.item ...
                       interrupt     noul
                       threat        score    none..critical
                       needs_deliberation noul
                                   ▼
                     resolve: read only the slot answers of the chosen action
                       act      ──► "Erik|command|Attack@Bandit Marauder"  ──► Skyrim
                       wait     ──► end request, no LLM call
                       escalate ──► continue into the LLM with an escalation note
```

Files:

| File                | Hook / role                                                                                   |
|---------------------|-----------------------------------------------------------------------------------------------|
| `globals.php`       | defaults for every `JEV_*` setting, loads the library                                          |
| `functions.php`     | adds `SetTacticalGoal` / `ClearTacticalGoal` to the action catalog offered to the main model; executes them server-side through `action_post_process_fnct_ex` (same path as Drink/Toast) |
| `prerequest.php`    | the tactical tick (see above)                                                                  |
| `context_pre.php`   | injects a `<tactical_goal>` section into the main model's prompt: goal, rules, last decision, last result, escalation note |
| `lib/jev_client.php`| minimal client for `POST https://api.typesafe.ai/v1/systemone` (choice / noul / score)         |
| `lib/jev_tactical.php` | pure decision logic + CHIM data helpers                                                     |
| `dryrun.php`        | `php ext/jev_tactical/dryrun.php [--live]` prints the request for a sample scene, optionally sends it |

### Strategic → tactical handoff

The main model receives two extra actions. When the player says "keep watch while I search the
room", the model can answer in character and call:

```json
{"action":"SetTacticalGoal","goal":"Guard the doorway while Dragonborn searches the room","rules":"Stay near the doorway. Do not start fights. Respond to threats aimed at Dragonborn or Erik."}
```

The goal is stored in CHIM's per-plugin namespace (`plugin_extended_data` -> `jev_tactical.goal`, written with `NpcMaster::setPluginData`, a single `jsonb_set`, so it never rewrites the rest of the NPC row) with a TTL (`JEV_GOAL_TTL_SECONDS`). A legacy `extended_data.jev_goal` is still read once if present.
`ClearTacticalGoal` removes it. While a goal exists, every prompt the main model sees carries a
`<tactical_goal>` section, so it knows what its body is doing and can change its mind.

### What triggers a tick

`JEV_TICK_TYPES` (default `funcret,bored,jev_tick`), and only for NPCs with a standing goal:

* `funcret`: the result of an action **this layer issued** came back from the game ("what next?").
  Results of actions the main model issued (Inspect, ReadQuestJournal, ...) are left alone so the
  LLM can talk about them.
* `bored`: CHIM's idle event, a natural low-frequency tick.
* `jev_tick`: reserved for a dedicated periodic request from the game side (see "Known gaps").
  It never reaches the LLM: act/wait/escalate all end the request, with escalation notes stored
  for the next real request.

### What Jev may choose

Only physical actions with bounded parameters (`jev_tactical_default_actions()`): Attack, Brawl,
Follow, FollowPlayer, ComeCloser, MoveTo, TravelTo, WaitHere, StopWalk, Relax, SheatheWeapon,
TakeASeat, GoToSleep, ReturnBackHome, PickupItem, GiveItemTo, CastSpell, Surrender; filtered by
what the NPC is allowed to use (CHIM's NPC/follower defaults or `functions/user_pref.json`) and
by whether concrete candidates exist this tick (no hostiles → Attack falls back to all actors;
no nearby items → PickupItem is not offered). Informational and social actions (Inspect,
CheckInventory, trade, gold, rituals, EndConversation, ...) stay with the main model.

Candidate lists come from what CHIM already tracks: `DataBeingsInCloseRange` (actors, with
`(hostile)`/`(in combat)` tags), `DataPosibleLocationsToGo`, `DataItemsInCloseRange`, and the
NPC's `metadata` (inventory, spells, live stats from `updatestats`).

### Decision rules

1. `needs_deliberation ≥ JEV_ESCALATE_THRESHOLD` → **escalate** (wake the main model).
2. action `Escalate` → escalate; action `Wait` → wait.
3. action confidence `< JEV_MIN_CONFIDENCE` → wait (counts toward the low-confidence streak).
4. For every slot of the chosen action read `<Action>.<slot>`: `none`, missing, not in the
   offered list, or low confidence → wait.
5. Otherwise **act**: the command is written into the current response exactly as the LLM
   connectors do, recorded in `actions_issued` (`original = jev_tactical`) and logged as an
   `infoaction` event so the main model later sees "Erik decides: Attack target=Bandit Marauder".
6. `JEV_LOW_CONFIDENCE_ESCALATE_AFTER` consecutive low-confidence waits → escalate with reason
   `low_confidence_streak` (the goal or vocabulary no longer fits).

Because questions in one request are evaluated independently, every conditional question states
its premise ("Assume Erik is about to perform 'Attack'. Which target ...?") and the resolver reads
only the answers that apply. Asking `action`, `target`, `item` as three unrelated questions would
produce well-typed nonsense.

## Configuration

Global settings (CHIM configuration UI, section "Jev Tactical Layer (Experimental)", or `conf/conf.php`):

| Setting                            | Default       | Meaning                                                  |
|------------------------------------|---------------|----------------------------------------------------------|
| `JEV_TACTICAL_ENABLED`             | `false`       | master switch                                            |
| `JEV_API_KEY`                      | `""`          | TypeSafe key; falls back to env `TYPESAFE_API_KEY`       |
| `JEV_MODEL`                        | `jev-latest`  |                                                          |
| `JEV_MIN_CONFIDENCE`               | `0.6`         | act only above this                                      |
| `JEV_ESCALATE_THRESHOLD`           | `0.7`         | wake the main model above this deliberation probability  |
| `JEV_LOW_CONFIDENCE_ESCALATE_AFTER`| `3`           | consecutive low-confidence ticks before escalating       |
| `JEV_GOAL_TTL_SECONDS`             | `900`         | standing goals expire unless renewed                     |
| `JEV_TIMEOUT`                      | `5`           | HTTP timeout                                             |
| `JEV_TICK_TYPES`                   | `funcret,bored,jev_tick` | request types that run a tick (conf.php only) |

Try the request shape without a game running:

```
php ext/jev_tactical/dryrun.php            # prints the JSON Jev would receive for a sample scene
TYPESAFE_API_KEY=... php ext/jev_tactical/dryrun.php --live
```

Debug data for the last tick is in `$GLOBALS["DEBUG_DATA"]["jev"]` (request, answers, decision,
latency, token usage) and in the log as `[JEV] Erik: Attack target=Bandit Marauder [confidence 0.91] in 0.12s`.

## Known gaps

* **No real clock yet.** Ticks piggyback on `funcret` and `bored`. A proper implementation sends
  a `jev_tick` request from Papyrus every 1–2 s (faster in combat) for NPCs with a goal. The
  server side of that is already in place; the mod side is not.
* **Combat state is coarse.** Hostility is inferred from the tags CHIM already attaches to nearby
  actors. Health of *other* actors, who is attacking whom and distances would make the state
  much sharper; the DLL knows them but does not send them today.
* **`funcret` only for tactical actions**: an action that never reports back (e.g. `WaitHere`)
  leaves `pending_action` set until the next tick overwrites it. Harmless, but the "last action
  result" the state carries can be stale.
* **Escalation on `bored`/`funcret`** continues into whatever prompt those requests normally
  build; the escalation note is added to the prompt but the request text itself is CHIM's default
  for that event type.
* Cost is dominated by state tokens (Jev charges input only, output is free). Keep
  `recent_events` and candidate lists short; the state is a compact JSON object, not the full
  chat context.
* Jev launched on 2026-09-15; the API surface used here (`/v1/systemone`, `choice`/`noul`/`score`,
  `answers` map with `choice`/`confidence`/`probabilities`) is what the public SDKs expose today
  and may change. Everything HTTP-specific lives in `lib/jev_client.php`.
