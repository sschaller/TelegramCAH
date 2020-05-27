# Cards Against Humanity Telegram Bot

## Play
1. Invite the bot into a Telegram Group by adding [@PlayCardsAgainstHumanityBot](https://telegram.me/PlayCardsAgainstHumanityBot)
1. To start a game, use `/start`. The default number of rounds is 10, but can be changed by adding `/start 20`.
1. People who want to join now type `/join`. The first player will be able to draw a Black Card, while all others can pick their answers.
1. As soon as at least 3 players have joined, the first player is able to judge their answers and select a winner.
1. The round will conclude and the winner will draw a Black Card. 

### Supported commands
| Command  | Description                                             |
| -------- | ------------------------------------------------------- |
| /start 5 | start new game with number of rounds                    |
| /join    | join game                                               |
| /leave   | leave game                                              |
| /stop    | stop game                                               |
| /dummy   | create a bot player that randomly picks cards / winners |
| /admin   | reset or import, only allowed for whitelisted players   |

## Install
1. To host the bot, https is required for the domain in order to use Telegram Webhooks.
1. `allow_url_fopen` needs to be on in php.ini
1. An example config can be found at [include/config.php.example](include/config.php.example), rename it to `config.php`.
1. Determine the subdirectory on the host and change `RewriteBase` in [.htaccess](.htaccess) and `urlPrefix` to it: e.g `/cah/`.
1. See [Telegram Bots](https://core.telegram.org/bots#6-botfather) on how to create a new bot.
1. After creating the bot, save the token to `telegramAPIToken`.
1. Create a new telegram game using a short name, save it to config `shortName`.
1. Save `dbName`, `dbUser`, `dbPassword` of a local SQL database in config
1. Set `botSecret` in config to a random string / password. This will be used to identify calls from Telegram Webhooks. 
1. Optionally telegram user ids can be added to `whitelist`. This will allow only those users to start a new game.
1. Go to url: HOST/urlPrefix/adminSecret
1. Setup Webhook and Reset Database
1. Try the Bot

### config.php
| Setting          | Description                                             |
| ---------------- | ------------------------------------------------------- |
| shortName        | Telegram Game short name received when creating game    |
| dbName           | Database name of local SQL instance                     |
| dbUser           | Database user of local SQL instance                     |
| dbPassword       | Database password of local SQL instance                 |
| telegramAPIToken | Telegram Bot token received when creating bot           |
| urlPrefix        | location of main folder on host                         |
| botSecret        | random string to identify telegram updates              |
| adminSecret      | random string to allow setting telegram webhook         |
| maxDummyPlayers  | number of dummy players per chat, 0: disabled           |
| whitelist        | whitelist users for admin access                        |
see [Telegram Games](https://core.telegram.org/bots/api#games) for more info

## Additional Card Packs
By default, [data/cards.json](data/cards.json) only includes the base set and the first expansion.

Further sets can be added by generating cards.json on [https://crhallberg.com/cah/](https://crhallberg.com/cah/). To install the cards, a reset is needed.