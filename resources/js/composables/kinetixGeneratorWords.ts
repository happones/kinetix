/**
 * Word lists for the generator's memorable strategies (passphrases, memorable
 * passwords, readable handles). Curated for Kinetix: common, neutral English
 * words of 3 to 7 letters, each verified against a dictionary.
 *
 * The sizes are the security: a word drawn uniformly from 256 adjectives
 * carries 8 bits, one from 512 nouns 9 bits. A six-word passphrase (three of
 * each) is about 51 bits — the strength of a four-word passphrase from the
 * classic 7,776-word lists. Shrinking a list weakens every passphrase.
 */
const words = (list: string): readonly string[] =>
    Object.freeze(list.trim().split(/\s+/));

/** 256 adjectives (8 bits each). */
export const GENERATOR_ADJECTIVES = words(`
able active agile alert alive amber ample amused arctic ardent artful astute
atomic autumn avid awake aware azure balmy basic bold bouncy brave breezy
brief bright brisk broad bronze bubbly busy calm candid casual cheery chief
chilly civic clean clear clever close cloudy cobalt cool copper coral cosmic
cozy crafty creamy crisp cubic cuddly curly daily dainty dapper daring deep
deft direct divine dusty eager early easy elated entire epic equal even
exact exotic expert fabled fair famous fancy fast fiery fine firm first fit
fleet fluent fluffy flying fond formal fresh frosty frugal full funny fuzzy
gentle giant gifted glad global glossy golden good grand grassy great green
gusty handy happy hardy hasty hazy hearty heroic hidden honest humble icy
ideal indigo inner jade jolly jovial joyful juicy keen kind kindly large
lavish lawful leafy level light likely limber linear lively local lofty
loyal lucid lucky lunar lush magic main major mellow merry mighty mild minty
misty modern modest moral mossy nimble noble normal novel oaken ocean olive
open orange oval pastel pearly placid plain plucky plush polar polite prime
proud pure quaint quick quiet rapid rare ready real regal rich rigid ripe
robust rosy round royal rugged rural rustic sandy savvy scenic secure serene
sharp shiny silent silky silver simple sleek smart smooth snowy snug social
soft solar solid sonic spare speedy spicy spry stable stark steady stout
strong sunny super sure swift tall tame teal tidy tiny topaz true urban
valid vast vital vivid warm wavy wide wild windy wise witty woody young
`);

/** 512 nouns (9 bits each). */
export const GENERATOR_NOUNS = words(`
acorn acre actor admiral album alcove alley almond alpaca amulet anchor
angle anthem apple apricot apron arbor arch archer arena armor arrow aspen
atlas attic autumn avenue avocado badge badger bagel bagpipe bakery balcony
ballad ballet balloon bamboo bandana banjo banner barge barley barn barrel
basil basin basket bay bazaar beach beacon bead beagle beam bean bear beaver
bell bench berry bicycle biscuit bison blade blanket blossom bluff boat
bonnet bonsai book boulder bowl bramble branch brass bread breadth breeze
brick bridge brook broom brownie bubble bucket buckle buffalo bugle bundle
bunker bunny burrow butte butter button cabana cabin cable cactus cairn cake
calico camel camera canal canary candle canoe canopy canvas canyon cape
capsule caramel cargo carpet carrot cashew castle cavern cedar cello chalet
chalk channel chapel cheese cheetah cherry chess chili chimney chorus cider
circle citrus city clam clay cliff clipper clock cloud clover coast cobalt
cobble cocoa coconut comet compass concert condor cookie copper coral cotton
cougar cove coyote cradle crane crater crayon creek crest cricket crown
crystal cumin cupcake curtain cushion cypress dahlia daisy dawn deer delta
denim desert dewdrop dial diamond diner dinghy dock dolphin domain donkey
doodle dove dragon drum dune eagle easel echo eclipse elbow elk ember
emerald engine estuary fable falcon fennel fern ferry fiddle field fig finch
fjord flag flame flint flute fog forest fossil fox frost fudge gadget galaxy
gale garden garnet gecko geyser ginger globe goose gorge gourd grape gravel
gravy grove guitar gull gully harbor harp hatch haven hawk hazel hedge heron
hill hive honey horse hummus hut ibis igloo inlet iris island ivory jacket
jaguar jam jelly jetty jewel jigsaw jungle kayak kelp kernel kettle kiln
kite kiwi knoll koala ladder lagoon lake lamp lark latte laurel lava ledge
lemon lens lichen lilac lily lime linen lion lizard llama locket lodge loft
lotus lynx magnet magpie mango maple marble marina market marmot marsh
meadow melody melon mesa meteor mill minnow mint mirror mitten mocha moon
moose mosaic moss motor muffin mural nebula nectar needle nest nickel night
noodle nugget nutmeg oak oasis ocean olive onyx opal orange orbit orchid
otter owl oyster paddle pagoda palace panda paper parade parrot pasta pastry
peach peak peanut pear pearl pebble pecan pencil peony pepper petal pewter
piano pickle pier pigeon pillow pilot pine planet plaza plum pocket poem
pond poppy portal prism puffin puzzle quail quarry quartz quiche quill
quiver rabbit radar radish rain raisin ranch rapids raven ravine reef relic
ribbon ridge river robin rocket rose rubble ruby saddle sage sail salmon
sand sandal scarf school scroll seal shell ship shoal shore shrub sierra
signal silk sketch sled sleet slope snail sonnet sorbet spice spider spire
sponge sprout spruce squash stable star statue steam stone stork storm
stream studio summit sunset swan table tablet tango teapot temple tiger
timber tinsel toast topaz torch toucan tower trail train tulip tumble tundra
tunnel turnip turtle tuxedo valley vase velvet violet violin vista voyage
waffle wagon walnut walrus wave whale wharf wheat willow window winter
wizard wolf wombat wren yacht yarn yogurt zebra zephyr
`);
