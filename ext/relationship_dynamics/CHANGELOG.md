# RelDyn changelog (CHIM 3.4.1 port)

Conceptual notes per version. Tags live on Ken's fork (KenStarwind/HerikaServer) as `reldyn-vX.Y`.
Design authority: D:\docs\relationship-dynamics-mdd.md + D:\docs\reldyn-design-decisions-2026-09-23.md.

## reldyn-v0.25 — the dark path, how relationships end and begin again, duty, and a face that shows it
Immaturity now has a shape. A mature NPC whose trust has fallen far enough decides they deserve better and leaves; an
immature one who trusts too much turns codependent and lets bad treatment land softer; one with neither has no floor
at all and can be used. All of it moves with who they are and how they're treated, never a switch.
Relationships can be committed, or sworn under an oath that strains and can break. When a romance ends, it ends by
character: as an ex, as something unresolved, or as friends, and some of those roads lead back. Neglect can tempt an
NPC to stray, and what happens next is who they are: some confess, some leave, some hide it.
Warmth travels through friends, and so do rivalries between rivals. Housecarls and sworn protectors build a duty bond
of their own that never turns into romance. A fight's thrill scales with the enemy, a near miss binds people together,
and a wide range of named moods colours how feelings are put into words.
Sharmat scenes it doesn't know get described from their tags, and each NPC keeps a voice of their own. Jev now reads
RelDyn's state and picks the NPC's body language and tone of voice, and a flirt that lands can make an NPC blush on
screen through OBlush (the game-side bridge is a local build, waiting on its first in-game check).

## reldyn-v0.24 — consent, the body's needs, the night hours, and profiles that grow
RelDyn now decides whether intimacy happens at all, and Sharmat handles what happens once it does; Sharmat defers to
RelDyn when RelDyn is present (a local Sharmat change). The decision is about who the NPC is: some never will, a quarrel
or the ick or pulling back makes it less likely, and a people-pleaser may say yes when they shouldn't.
The party's condition reaches RelDyn from the survival mods (hunger, thirst, fatigue, cold, wet, a campfire and who built
it, dirt and blood) through a small reporter in the game plugin, so a cold, hungry march wears on people and a fire
someone built warms them toward that person. Vampires keep the night: cranky from dawn to dusk, worse when they haven't
fed, worse still in the sun.
When CHIM rewrites an NPC's bio, the profile is read again and blended in, slower for those set in their ways.
An ordinary NPC is now neutral on passion and jealousy (the charm curve was retuned so a silver tongue still can't win
Aela over); grey weather cools harder; a toxic bond swings harder on the way down; passion has one set of words, faint to
burning. The fear of losing someone plays out by character (appeasing, clinging, controlling, picking a fight); a
fearful NPC pulls away for a while after intimacy; fighting side by side counts by how much the NPC enjoys it; and each
NPC's standing can be set in the editor.
Everything RelDyn writes for the LLM, the player or the settings now uses each character's own gender. RelDyn is for
everyone.

## reldyn-v0.23 — a flirt that lands, the fear of losing someone, news that travels like news
A flirt that lands skips the slow climb of attraction only as far as the NPC already finds the player their type, or
looks up to them; otherwise it climbs like any other gain. Interest that isn't shown still counts toward a romance, and
a shy NPC needs a deeper bond before saying it out loud. The fear of losing the player is its own feeling now, strongest
in anxious and toxic NPCs, and it costs them some of their own trust and ease while it lasts. Fighting side by side
counts as time together for those who enjoy a fight.
News no longer travels by telepathy. Whoever was there knows at once; everyone else hears it later, sooner the closer
they live and the closer they are, or straight away if the two talk. A rival the player is close to stings more, and
maturity softens jealousy without making anyone immune.
Those who need reassurance ask for it in words or in deeds, depending on who they are. The morning after a drunken
night is uncertainty ("was that too soon?"), never shame. RelDyn now damps CHIM's own NPC-to-NPC romance toward
someone who is committed to the player, and marked moments reach the diary, through two small hooks on Ken's fork
that do nothing without RelDyn. The old give/trade request path, which CHIM no longer uses, is gone.

## reldyn-v0.22 — let in, and pulling back
"Closed off" is no longer a permanent verdict. Two things replace it. Being let in is earned and lasting: it grows with
comfort and trust in any kind of bond, friendship and family as much as romance, and a guarded NPC simply earns it
slower. Until then they haven't let the player in yet, and the text says "yet".
Pulling back is a mood that comes and goes, even deep into a bond. A stormy inner weather, needs that go unmet and
unresolved hurt press on them; the less mature they are, the more their mood drives it, and it fades when things ease or when
the player meets what they're missing. How it shows is who they are: a mature NPC says plainly what's missing and asks for
it, an in-between one means to and it comes out sharp, an immature one pouts or picks a fight, and their attachment style
colours it. The NPC editor, Jev and the state dump show both, and a switch restores the old behaviour.
The gating preview now says its tier table changes only what they know of the player, not how they feel.

## reldyn-v0.21 — gifts that count as gifts, ripples through their circle, the feelings that grow from the rest, and fame that follows what you joined
What you hand them is read for what it is: food, drink and potions are help, anything else is a gift, and each handover
counts once however many ways it was seen. Making up after a quarrel warms them more again in ordinary play, and open
flirting or an intimate scene in front of someone who cares about you now stings them right then.
Word travels. Something big you do to one person reaches the people who care about them, gently and second-hand, the
next time they talk to you; an enemy of theirs hears it the other way. NPCs talking among themselves now know a little
about each other's feelings toward you, more as the bond deepens.
Their inner life fills out: the emotions that grow out of the rest (loneliness, earned security, impostor feelings and the
others from the design) appear when their ingredients are there, and only the deepest couple speak. Reunions and the
little hints of what they like ride on what you just did, and name them instead of assuming a pronoun.
Their warmth and love languages keep up with who they are when their personality is read or edited, and the diary's moments
no longer stall out on a steady pace of play.
The body and the blood: the states the game can actually see (hurt, rain, cold, a clear night) count; hunger, fatigue and
the like wait until something can sense them. Whoever heals you earns the trust, a werewolf comes back to themself after
the change, and the old flat penalty for losing a fight is gone, since their own fall already says who they are.
Fame follows the sides you joined (the civil war, the Bards, the Dawnguard or the Volkihar, a thane's title) and stays
after the quest ends. A replay tool and a read-only health check for the live pipeline join the repo for testing on the
real game.

## reldyn-v0.20 — love languages that work, and bio reads that are not inflated
Love languages came out the same for everyone (quality time, then words of affirmation): the secondary read MARAS,
which is retired, and the race map never matched CHIM's race names ("NordRace", "NordRaceVampire"). The primary now
follows their attachment first (anxious and toxic corner weights summing to 0.5 or more seek reassurance: words of
affirmation, config `love_language_attachment`), then their own temperament (the nearest preset of their trait vector,
config `love_language_primary`), the
secondary likewise (`love_language_secondary`); when the two come out equal the secondary is the language of their
next-nearest temperament, and only then rotated. The core race (normalised: Nord, Orc, Redguard: acts of service;
Breton, Dunmer: words; High elf, Imperial: gifts; Khajiit, Wood elf: touch) is a minor prior used only when there is no
temperament. Love languages already stored are kept.
Two corrections on how traits turn into numbers, each behind its own switch (`traits.read_calibration`; off is the
earlier behaviour exactly). `relevel` (on): the intimacy-need and attachment regressions (A26, C2) were fitted on the 13
presets, whose averages are not the middle, so a middle (all 0.5) vector carried an offset nobody chose (+0.20 emotional
need); they are re-levelled so that it adds nothing, with the slopes unchanged, and a preset still gets exactly its table
row. `relevel_mult` (off): the same for the multiplier regressions (passion, jealousy, plasticity, resistance), whose
values are tuned against rulings; five of them (y_affinity up/down, y_valence_up, y_respect_up, resist_trust) stay
unlevelled even then, as re-levelled they break the wrong-way guard. `leniency` (off): the bio read is the profile and is used as read; switched on, each trait the model read is
moved at use time by the seed population's mean (`x' = x - read_mean + 0.5`; stored reads stay raw; a hand-set vector,
a preset or a label is never touched).

## reldyn-v0.19 — RelDyn gets its own pages: the hub, every part of them, and how they see you
The plugin button in CHIM's Server Plugins page now opens RelDyn's hub, and from there every RelDyn page is a click
away. The hub holds every setting RelDyn has, grouped by what it shapes, each showing its default, whether it was
changed and a way to put it back; a list of the feature switches, with the ones that ship off standing out; prompt
gating, with its texts, its tiers, the fame of the player and the map of holds, and a preview of what any NPC would know
about the player at their real bond or at any other, which changes nothing; and the formulas and tables for reference.
Saving keeps only what differs from the defaults, so a later change to a default still reaches the settings nobody
touched.
The NPC editor shows everything RelDyn holds for one NPC on one page: each dimension as stored, where it rests, how it
reads toward you and what a passing state is holding on it; their traits with the preset picker and, beside each trait,
where it came from (the line of their bio it was read from, their class and voice, a preset, or a vector set by hand, whose
bio is never read); their attachment, love languages, what they love and hate, their intimacy need, what they find
attractive and how high their bar is, jealousy, resentment and its record, the fulfillment spider of each relationship,
their switches and clocks, and the states they are in. Every value shows whether it is their own derivation or an edit;
changing one stores an override, leaving it alone keeps it derived, and each field, each section or the whole NPC can
be put back. Every save goes through the same merge the game's own writers use. The April editor, which nothing opened
any more, is gone.
The player profile page shows how NPCs have come to see the player, from what the evaluators saw them do: the
qualities they are read on, with how much each rests on and where it is heading, their style, their attachment pattern and whether it is
shifting, how they show care, where they look for validation, their standing, and a few plain sentences about them. It
makes a shareable spider-graph card, with or without the player's name and never with anyone else's, and shows for any
NPC what they need from the bond against what it gives them. Whether NPCs sense this profile at all is the player's
choice on that page, and it starts off.
The debug pages come with them: a dry run that puts one evaluation, typed in or taken from what is waiting or was
already applied, through the real pipeline and throws the result away, showing what would have changed and why; and a
dump of one NPC's whole current state.
None of these pages changes anything by being opened, reads anyone's bio or calls a model; every change needs a form
from the page itself, and everything shown from the game is escaped.
The NPC editor's Save works in a browser: the Dimensions, Attraction and Attachment forms no longer fail the browser's own
step check on a value like 32.77, and saving the Interests form no longer pins every interest the browser had nudged to
the slider's grid. A save also stops putting back what the game changed while the page sat open (an affinity gain, a
trust gain, a switch changed on another page): only what you changed is written, on the editor and on the hub. Their record
of grievances shows what each one was, and the fulfillment spider is the same drawing on the editor and the player
profile, sized so its words stay readable and whole at phone width; the shareable card scales to the screen instead of
scrolling sideways. In the hub every setting reads in plain words: trait codes by name, abbreviations spelled out,
a name that said "Beauty" seventeen times now says whose and which, every switch says what it switches and the ones that
ship off say so, the big keyword and weight tables say what a row means and what its two numbers are, and each section
says what it is. The editor's intimacy need now shows what their next turn would store, their race, their blood and their love languages included; before, it left them out and Aela's read as needing connection more than touch.

## reldyn-v0.18 — Their own drink and the morning after, what follows a night, memories that keep what they felt, and the mirror
A bond breaks while you are away only once the absence feels intentional to them, past what they would excuse given who
they are and how fulfilled the bond was when you left: the fearful and the anxious break sooner, the secure and the
mature give you longer. When it breaks, the hurt holds them back on your return until you have been warm to them since.
A romance gone cold wears the bond down while you are there without warmth, not again while you are away, and a
marriage without attraction is loveless by nature, not rotting. Care after their fall is the rescue itself, paid once,
by who they are. The moment on top of the bond is the size their temperament makes it and halves within minutes of quiet;
their warmth feels each held state once, fades while you are away by who they are, and a catastrophe closes it to anyone
but their partner for a while.
They drink on their own account now, read from the game's own lines of their consuming. Each drink takes a little more of
their judgement than the last: laughing easily, then their guard and their standards down and blind to desperation, and at
last without the floors that keep them from slipping. It is a state, not who they are: it wears off on the game clock and
leaves them exactly themself. Skooma, the sap and heavy drinking build a dependence and a tolerance: each high a little
less, the craving growing with the hours, then the sickness of going without, until the next use or a healing potion
that eases it without feeding it. While they depend on it they do not grow up, unless someone who cares tells them to
stop; once they know they need to stop they can set themself to stay clean, are ashamed when they slip, and are steadier
for the days they stay clean. The player's own drinking still reaches them only as worry.
A scene the game reports is followed by its context, not by the act: a partner who trusts you deepens, a newer romance
warms, one who backs away from closeness does (reading someone who wants closeness and fears it as the draft's
"manipulated" night is opt-in until Ken rules on it), a casual night stays light, and if they have a partner who is not you it is guilt. The glow lasts a little while and is
then gone exactly. Every scene makes their pulse race; the context decides whether that reads as warmth or unease, and
the evaluator is shown it, never asked to score it. Their pulse and their mood now settle with time instead of staying
where the last event left them. How they carry themself toward you (steady and protective, cold, yielding, bitter)
moves with how much they respect you and believe in themself, and how much they trust you and are at ease with you,
around the shape their personality gives them. A gift counts by their second love language too; a stolen gift, or a present
they know was someone else's first, is no gift at all.
What they did drunk waits for their sober self. The morning judges a drunken night, and their first diary page after it
looks back at what they let happen: whatever they would not have done sober is regretted, the more so the more they
expect of themself, and a shallow mind never looks back. However many ways their sober self looks back on one night, and
however many scenes it held, it is one verdict: for their shame, their ease and their trust alike the heavier counts, never
both, and a second scene while they are still drunk never brings the morning early. Drink still in them is drunk, even
when the moment of the mug has long passed; and a drink is dated when they had it, so last night's mead is not this
morning's drunk. A drink or two of an evening is a habit, not a dependence; getting drunk every night is.
Gold, ammunition, a mead or a potion are never "someone else's first": only a thing they could recognize is.
Before an exchange becomes memory, who they were in it is written around it in words: how the moment landed on them by
their own measure, what they carry, how they attach, their mood, and how they carry themself toward you as they do now.
One hurtful word said to four people is four different memories (opt-in: it writes into CHIM's own memory, right after
the exchange, at the exchange's own place, so CHIM packs it with the exchange). Some firsts are kept for good with the place they happened, and coming back there brings them back to
the NPC for a while, or an ache while the bond is strained, with a small moment of passion their own size.
The player now has a profile of their own, built only from what the evaluators saw them do: maturity, trust, warmth,
respect, how comfortable people are around them and self-confidence; where they look for validation; their charisma;
their attachment pattern (read from how often they come back, visit by visit, never from the next line of one
conversation); the love language they show. Mirror mode reads play alone; Character mode holds an authored
role that play moves slowly. A trust record travels: strangers meet a reliable player with a little more trust, a liar
with less. Whether NPCs sense the rest is opt-in; it follows the game back on a load, and a read API feeds the spider
graph.

## reldyn-v0.17 — The moment on top of the bond, the urge they act on, and what time away does to it
Passion now has two parts. The floor is what you have earned with them and changes slowly; on top of it, a moment (a
touch, a flirt, a topic they love, being helped up after a fall) makes their heart race for a little while and then
fades over the next few exchanges, and faster while you are gone. How big the moment is depends on who they are: the
restrained feel it least, and an NPC barely drawn to you feels none. What they show follows both: their warmth toward
you is no longer a number of its own but how much they feel for you and how at ease they are with you, together, so it
opens in a good moment and closes when they stop feeling safe. Excitement feeds desire, and their mood colours it; a
move you make lands by what you are to them, welcome from a partner or a crush, shrugged off by an acquaintance,
unwelcome from a stranger, and never welcome while the ick lasts. The weather now pulls their mood toward its own
feeling and holds it there instead of piling up.
How far passion can go now depends on what you are to each other: a friend's has a ceiling unless they are drawn to
you and built to let it grow, a partner's has a floor it will not cool below, and an ex's does not grow at all, not
even for a moment. Big fights stir them more than small ones, and when they go down and your next word to them is
care, it moves them by who they are: the self-reliant barely, the anxious deeply, and it can make them lean on you more.
They now have a short band of wants that rise and fall with the moment: to be close to you when you are alone, to
shield you when you are hurt, to seek your company when they have missed you, to get themself out of danger, to go and
look at a place they have never seen. Each fires by how guarded and how sure of themself they are, and shows in their own
way (plainly, held back, in false starts, in a joke). When what they want right now pulls against what they are working
toward in life, you see the struggle, and their temperament decides which wins.
Leave someone you are close to long enough and the absence starts to feel intentional. Come back past that point
and it lands on who they are: an anxious or fearful partner lets it spill out, a guarded one lets the walls go back up,
a mature one tells you calmly what they need or steps back rather than exploding. It happens once per absence, costs
them some comfort, and so some of their warmth toward you, and some trust. A fight left unresolved, or a romance gone
cold, slowly and permanently wears the bond down after a week without a single good moment between you; one warm
exchange starts the week over. Coming home after a long time away is itself a strain for now, and a strained bond
reaches for nothing, however much they missed you. A reloaded save forgets any fall and rescue that happened after it.

## reldyn-v0.16 — Shame is met gently, the style you bring is graded, and the ick is theirs
When they walk off in shame, going to them is not chasing them: a gentle word can reach them, they can tell you what they
are ashamed of, and you can forgive them, and each of those brings them back sooner. Admitting something they are ashamed
of is a confession; merely sharing a secret is not. A partner's trust and comfort show how high they really are
instead of all reading as the top. The kind of presence you bring (a steady rock, a challenging catalyst, a charmer)
is judged from how you actually behave in each exchange, not guessed from swings in affinity, and it is what a mature
NPC feels as pressure when you then push for more. Muiri now wants closeness and fears it at once, from the start.
When they answer another NPC, it is their exchange, not a turn of yours. The ick is now only pressure they did not
want, measured from their side: the touches the game reports inside your romance are the romance itself, a partner
who has not warmed yet is not cold toward you, answering you in kind is answering, and one pushy moment is resented
once, not twice. Grief and its turning points are told the same way for every character.

## After reldyn-v0.15 (batch O review, untagged) — Crisis, grief, the ick and the parasite; and a suitor's flirt is not yours
The protocols arrive. A catastrophe forks them on the game calendar: someone they trust around them pulls them up, no
one pulls them down, and in between a window stays open until an anchor comes or the time runs out; the dead anchor
no one, nor the partner who betrayed them. Grief runs through its phases on the calendar, quiet in a mature NPC and
raw in an immature one; a widowed NPC does not let a new bond past a ceiling for a while, and the one they lost is idealised,
then remembered. Pressing an NPC who is cold toward you turns every gain of passion into a loss until you back off
and they are at ease again. A bond that is only gifts turns transactional, and its passion drains fast unless it is fed.
A rechat in which another NPC flirts with them is theirs, not yours: their reply is steered only by how they turn the suitor
aside, and nothing of your bond moves with it (no passion, no contact, no needs met). Everyday words between
companions are no courtship, a type core set between them long ago is no move now, and they drift only when you have
actually let them feel far away. Time in a place, a fight they only watched and their own drink meet their needs with you
only when you were actually with them. Quest friction on duty is not held against you in their resentment either. Someone
who already knows you hears no first impression of you on the upgrade, and a rumour held at the edge of their range gives
back exactly what it took. Their backstory's goals stay theirs however long nothing feeds them. Twelve gifts in one handover
are twelve, and their drink is found behind other people's meals.

## reldyn-v0.15 — Whom they save themself for, what they will not do, what they want, and what they make of it
They can be yours before anyone says so. A pull toward you grows out of their feelings (the spark, how deep the bond
runs, how well you meet their needs) and is shaped by who they are: a monogamous, loyal, grown NPC holds steadiest, a
volatile or avoidant one keeps a door open. When another NPC courts them, they turn the suitor aside in their own way (plainly,
coolly, sharply or flustered) and their interest in the suitor is held down. A title makes the pull stronger but never creates
it, and they name you only once there is one. Leave them unmet or alone for long and it loosens, the anxious first,
until they are drifting. Being there for them now means actually being with them: a follower you never talk to is not
meeting their needs, and those needs are kept per relationship so other bonds can have their own later.
They can say no. Under enough distrust, disrespect and resentment they refuse an order as they are (cleanly, with a yes
that means no, or with silence), and the refusal is real: following, trading and giving come off their list, while
leaving and ending the talk stay. A people-pleaser swallows it and turns it on themself. If a quest ties them to you,
they do the task coldly instead, and your slights land softly for its length.
They want things of their own. Goals form from their story, from how the bond is going and from what they love, grow as
the world and your company feed them, and fade if nothing does; the need to become better than they are can form and
hold, or slip into self-blame. The quest journal reaches the NPCs it names. What you are known for meets them before
you do, read their way, and fades as they come to know you. What they drink, read or wear means what it means to them.
They reflect when core writes their diary. The moments that mattered are kept for their next entry, and when it comes they
look back at who they have been since, as deep as their maturity allows: growing lifts them, going nowhere weighs on
them, spiralling costs them. Pages written drunk wait for their sober self.
Where these meet: a suitor's move on them is no rival of yours and their jealousy over your nights out is no crack in
their pull; a partner who has stepped back from the romance holds themself for no one any more; a refusal is not a
step-back and does not end the probation of a boundary, and a step-back is not a refusal.

## reldyn-v0.14 — What they carry, what the moon does to them, and who they slowly become
A grievance is now said out loud. When resentment builds past their own point (an anxious partner speaks early, an
avoidant one holds it long), they bring it up to your face, naming what actually happened, never a score. How it
comes out is who they are: a mature partner says it calmly, once; one in between means to say it evenly and it comes
out in their style; an immature one blows up, and it can happen again. Saying it takes some of the weight off. If you
keep doing it after a mature partner has spoken, they draw the one calm boundary, and then step back from the
romance. A people-pleaser never says it: they turn it on themself, the guilt seeps into how safe they feel with you,
and when you ask gently and they open up, it lifts. Walking away now closes the door in core too: a romance ends as
an ex, a friendship as estranged.
Vampires and werewolves follow Skyrim's real moon. Aela and the Circle carry the beast blood, read from their
factions: by day it only simmers, on other nights it stirs, and under the full moon it takes their composure, so the
same grievance they would mean to say evenly by day can come out as a blow-up that night. Coming back from beast form
leaves them ashamed. Serana is sharp and hungry at night and worn by day. None of this touches anyone who is not a
creature, and none of it changes who they are underneath: a full moon or a wound is taken back exactly when it ends,
and it no longer counts toward the slow drift of their character.
That drift is new: days of real contact slowly move who they are toward the bond you share, within limits, while
waiting and sleeping move nothing. How close you are now colours how a feeling reads (the same trust feels high
with a partner and low after a betrayal) without changing what is stored. The same words land differently by
closeness and by who they are: a guarded NPC shrugs off a stranger's insult that would wound an open-hearted one.
The eval now also tells whether you were courting them, whether you served what they are set on, and whether they were
keeping up a front: your charm is judged from what you actually did, their goals last the evening instead of seconds
and only the one you helped with is fulfilled, and a proud, guarded NPC can keep a mask of ease in front of people
they do not trust, which costs them and can slip (still switched off by default). Memories keep only the event.
Fights count for the one who fought: witnesses feel less, a kill streak builds, a fall is met with fight or with
fear by temperament, and the afterglow fades with play instead of lasting forever. When you are badly hurt, everyone
near you feels it, indoors or out, and it lifts when you heal.
Where these meet, one voice is kept: the confrontation is the only place grievances are raised, and a worry beside
a calm boundary stays calm even on a night the moon has them.

## reldyn-v0.13 — Who they are decides what they want, what they forgive and what they worry about
Their standards come from their own personality now: a selective, mature, self-assured NPC sets a high bar on every
pillar of attraction and a shy, open one sets a low bar, instead of everyone sharing the same line. An asexual NPC
can still fall for you, through time together, kind words, reassurance, confiding and gentle touch; their longing
carries no desire, physical intimacy never comes into play and Sharmat stays closed.
A partner who stays home now finds out about your nights out and reacts as who they are. They notice when you come back
late from the tavern with drink on you, and they hear it when you mention the night yourself. Two feelings answer:
jealousy about the people circling you, which trust softens a lot, and a new worry for your safety, which trust
softens only a little. A crowded market is not a risk; the tavern at night is. What matters is repetition: a mature
partner tells you once, plainly, what they value; the next time it shows; if it keeps happening within the week it
becomes a real grievance, they draw a calm boundary, and if it goes on they step back from the romance. A less mature
one accuses, tries to forbid it or sulks, and in the end it boils over. One night counts once however they learn of
it, and reassurance takes the edge off. Witnesses telling them comes later.
Anxiety now counts once, from how they attach rather than again from their temperament. Trust is slow to win and
quick to lose. A guarded, avoidant slow burn no longer stacks into an endless climb. Falling in battle is who they
are: the confident and proud fight harder, the reactive and unsure panic, and every fall is a jolt. Values that turned
the wrong way just off a preset now follow the traits, the eval sees their personality in words, Jev sees the numbers,
and the editor lists every preset. CHIM's once-a-second poll now only keeps the play clock and save loads in step and
never touches a bond, so an NPC set as the server default no longer has their absence erased every second.

## reldyn-v0.12 — Personality read from each NPC's own bio
Each NPC's personality now comes from their own CHIM bio: one read turns it into the ten traits, each backed by a short
quote from the bio, blended with small hints from their voice, class, faction, skills and (a little) race. Reads stay
near the middle unless the bio is clear. Around 100 key NPCs come pre-read; anyone else is read in the background
the first time you meet them, after the conversation work, and keeps their hints until then. Ashe is never read: their vector is
Serene's hand-set, spoiler-free conclusion (Stoic-leaning, resilient, slow to warm, maturity 75). Ysolda is no longer
forced to be Anxious (the old switch keeps their old preset). A quote only counts when it shows that trait of that
character: an oath to a hold is duty, not protectiveness; a quest item or a child is not a partner to be jealous
over; a spouse's resentment is not their partner's coldness; a job or an aim is not a strong sign; taking pride in one's
work is not vanity. Between the old temperaments the blend no longer makes spikes at a preset, though a few values
still turn back briefly near one. The old class-based vote is still there as a switch (traits.assignment 'label'),
and switching back to it restores everyone's old personality.

## reldyn-v0.11 — Personality engine under the hood
Temperaments are now presets inside a trait engine (guard, expressiveness, confidence, pride, resilience, reactivity,
warmth, restraint, possessiveness, protectiveness). Nothing behaves differently yet: every NPC still sits exactly on
their old temperament. This is the groundwork for reading each NPC's personality from their own bio.

## reldyn-v0.10 — Play clock on game time
Play time now comes only from the game's own event log. Waits, sleeps, fast travel and save loads never count as time
spent together, and results no longer depend on real-world time between messages.

## reldyn-v0.9 — Attraction as an uphill
Anyone can spark interest; past that, how an NPC's feelings grow depends on how close you are to what they're drawn to.
Far from their type is a steep climb, not a wall; charm helps you climb; only true non-negotiables (orientation,
asexual/aromantic, rigid tastes) close the door. The old hard friendzone cap is gone.

## reldyn-v0.8 — Attachment as two sliding scales
"Guarded" no longer means "avoidant". Attachment is fear of abandonment and discomfort with closeness, each on its own
scale, and both drift with experience: earned trust brings them down, neglect and betrayal push them up.

## reldyn-v0.7 — Feelings, not numbers
Everything RelDyn tells the LLM is behaviour and subtext, never numbers or "you feel X". Intensity shows in how they
talk. Jev gets an explicit numeric state block. Loading an earlier save keeps RelDyn consistent with CHIM.

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
it moves an NPC depends on who they are: an immature, jealous NPC takes an insult harder; an egocentric one soaks up
praise. Jealousy and resentment are real and separate.

## reldyn-v0.2 — Foundations
Affinity on CHIM's real scale, CHIM's relationship type as the source of truth, a clean fresh start, temperament from
CHIM's own NPC data, and time on the game calendar: time doesn't heal a fight, contact does, and disappearing for a
month hurts every bond.

## reldyn-v0.1 — Port to CHIM 3.4.1
RelDyn moved onto CHIM 3.4.1 with its April state bugs fixed (feelings no longer reset on save, affinity can go down,
no lost or stale updates).
