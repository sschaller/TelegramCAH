<header>
    <div class="wrapper">
        <? $this->drawCard($game->blackCard) ?>
    </div>
</header>
<main>
    <div class="wrapper">
        <ul class="main-carousel">
            <? foreach ($players as $i => $player) { ?>
            <li class="carousel-cell">
                <div class="player-picks shadow">
                    <h1><?=sprintf(translate('nr_player'), ($i+1)) ?></h1>
                    <? foreach ($player->picks as $pick) { $this->drawCard($pick); } ?>
                </div>
                <div class="action shadow">
                    <a href="<?=self::$config['urlPrefix'] . 'play/' . $game->player->token?>" class="button" data-picks="<?= Game::getPickIds($player->picks); ?>" id="best">Test</a>
                </div>
            </li>
            <? } ?>
        </ul>
    </div>
</main>
<footer>

</footer>