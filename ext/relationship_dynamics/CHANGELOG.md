# RelDyn changelog (CHIM 3.4.1 port)

Conceptual notes per version. Tags live on Ken's fork (KenStarwind/HerikaServer) as `reldyn-vX.Y`.
Design authority: D:\docs\relationship-dynamics-mdd.md + D:\docs\reldyn-design-decisions-2026-09-23.md.

## Unreleased — Personality phase 3: the deliberate fixes
Anxiety now counts once. An anxious NPC's clinginess comes from how she attaches, not a second time from her
temperament: reunions, jealousy, missing you while you're away and minding neglect no longer stack it two or three
times, and how much absence hurts follows how possessive she is. Trust is slow to win and quick to lose: a guarded
NPC no longer loses trust as slowly as she gains it. The maturity column of the resistance table is gone (her
maturity type already covers it), the unreachable Volatile row is deleted, and Humble still resists nothing.
Stacked slow-burn romance (guarded and avoidant) needs at most 2.5x the moments, not 4x. Falling in battle is who
she is: the confident and proud fight harder, the reactive and unsure panic, and every fall is a jolt. A few values
that turned the wrong way just off a preset (coordinates near Bold, tier retention near Independent) now follow the
traits. The eval sees her personality in words, Jev sees the trait numbers, and the editor lists all 13 presets.

## reldyn-v0.12 — Personality read from each NPC's own bio
Each NPC's personality now comes from her own CHIM bio: one read turns it into the ten traits, each backed by a short
quote from the bio, blended with small hints from her voice, class, faction, skills and (a little) race. Reads stay
near the middle unless the bio is clear. Around 100 key NPCs come pre-read; anyone else is read in the background
the first time you meet her, after the conversation work, and keeps her hints until then. Ashe is never read: she is
Serene's hand-set, spoiler-free conclusion (Stoic-leaning, resilient, slow to warm, maturity 75). Ysolda is no longer
forced to be Anxious (the old switch keeps her old preset). A quote only counts when it shows that trait of that
character: an oath to a hold is duty, not protectiveness; a quest item or a daughter is not a partner to be jealous
over; a wife's resentment is not her husband's coldness; a job or an aim is not a strong sign; taking pride in one's
work is not vanity. Between the old temperaments the blend no longer makes spikes at a preset, though a few values
still turn back briefly near one. The old class-based vote is still there as a switch (traits.assignment 'label'),
and switching back to it restores everyone's old personality.

## reldyn-v0.11 — Personality engine under the hood
Temperaments are now presets inside a trait engine (guard, expressiveness, confidence, pride, resilience, reactivity,
warmth, restraint, possessiveness, protectiveness). Nothing behaves differently yet: every NPC still sits exactly on
her old temperament. This is the groundwork for reading each NPC's personality from her own bio.

## reldyn-v0.10 — Play clock on game time
Play time now comes only from the game's own event log. Waits, sleeps, fast travel and save loads never count as time
spent together, and results no longer depend on real-world time between messages.

## reldyn-v0.9 — Attraction as an uphill
Anyone can spark interest; past that, how an NPC's feelings grow depends on how close you are to what she's drawn to.
Far from her type is a steep climb, not a wall; charm helps you climb; only true non-negotiables (orientation,
asexual/aromantic, rigid tastes) close the door. The old hard friendzone cap is gone.

## reldyn-v0.8 — Attachment as two sliding scales
"Guarded" no longer means "avoidant". Attachment is fear of abandonment and discomfort with closeness, each on its own
scale, and both drift with experience: earned trust brings them down, neglect and betrayal push them up.

## reldyn-v0.7 — Feelings, not numbers
Everything RelDyn tells the LLM is behaviour and subtext, never numbers or "you feel X". Intensity shows in how she
talks. Jev gets an explicit numeric state block. Loading an earlier save keeps RelDyn consistent with CHIM.

## reldyn-v0.6 — Spells by subject, blended identity, two kinds of intimacy
Nature magic isn't bookish; a bard who communes with animals reads as part druid. Intimacy need is physical and
emotional, different per character (Aela physical, Ashe connection).

## reldyn-v0.5 — Who you are, attraction, fulfillment, romance
RelDyn reads your character from the game (skills, deeds, gold moved). NPCs judge you by their own standards. Neglect
means unmet needs, not just absence; mature NPCs state a boundary and step back if nothing changes. RelDyn owns
romance and hands intimacy off to Sharmat.

## reldyn-v0.4 — Places and things feel different to different people
Where you are and what you share (places, gifts, topics) comes from CHIM's own data and is felt through each NPC's
likes and dislikes: the same Dwemer ruin fascinates Ashe and reads as a battlefield to Aela. MinAI is no longer needed.
RelDyn owns the affinity number (small marked CHIM hook, fork only).

## reldyn-v0.3 — RelDyn listens to conversations
RelDyn's own evaluator reads each exchange and turns it into feelings and tags (insult, gift, neglect...). How much
it moves an NPC depends on who she is: an immature, jealous NPC takes an insult harder; an egocentric one soaks up
praise. Jealousy and resentment are real and separate.

## reldyn-v0.2 — Foundations
Affinity on CHIM's real scale, CHIM's relationship type as the source of truth, a clean fresh start, temperament from
CHIM's own NPC data, and time on the game calendar: time doesn't heal a fight, contact does, and disappearing for a
month hurts every bond.

## reldyn-v0.1 — Port to CHIM 3.4.1
RelDyn moved onto CHIM 3.4.1 with its April state bugs fixed (feelings no longer reset on save, affinity can go down,
no lost or stale updates).
