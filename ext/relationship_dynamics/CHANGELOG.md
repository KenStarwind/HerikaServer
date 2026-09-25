# RelDyn changelog (CHIM 3.4.1 port)

Conceptual notes per version. Tags live on Ken's fork (KenStarwind/HerikaServer) as `reldyn-vX.Y`.
Design authority: D:\docs\relationship-dynamics-mdd.md + D:\docs\reldyn-design-decisions-2026-09-23.md.

## reldyn-v0.13 — Who she is decides what she wants, what she forgives and what she worries about
Her standards come from her own personality now: a selective, mature, self-assured woman sets a high bar on every
pillar of attraction and a shy, open one sets a low bar, instead of everyone sharing the same line. An asexual NPC
can still fall for you, through time together, kind words, reassurance, confiding and gentle touch; her longing
carries no desire, physical intimacy never comes into play and Sharmat stays closed.
A partner who stays home now finds out about your nights out and reacts as who she is. She notices when you come back
late from the tavern with drink on you, and she hears it when you mention the night yourself. Two feelings answer:
jealousy about the people circling you, which trust softens a lot, and a new worry for your safety, which trust
softens only a little. A crowded market is not a risk; the tavern at night is. What matters is repetition: a mature
partner tells you once, plainly, what she values; the next time it shows; if it keeps happening within the week it
becomes a real grievance, she draws a calm boundary, and if it goes on she steps back from the romance. A less mature
one accuses, tries to forbid it or sulks, and in the end it boils over. One night counts once however she learns of
it, and reassurance takes the edge off. Witnesses telling her comes later.
Anxiety now counts once, from how she attaches rather than again from her temperament. Trust is slow to win and
quick to lose. A guarded, avoidant slow burn no longer stacks into an endless climb. Falling in battle is who she
is: the confident and proud fight harder, the reactive and unsure panic, and every fall is a jolt. Values that turned
the wrong way just off a preset now follow the traits, the eval sees her personality in words, Jev sees the numbers,
and the editor lists every preset. CHIM's once-a-second poll now only keeps the play clock and save loads in step and
never touches a bond, so an NPC set as the server default no longer has her absence erased every second.

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
