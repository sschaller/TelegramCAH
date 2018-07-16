<header>
    <div class="wrapper">
    <? $this->drawCard($game->blackCard) ?>
    </div>
</header>
<main>
<div class="wrapper">
    <ul class="cards ia" data-pick="<?=$game->blackCard['pick']?>">
        <? foreach ($game->whiteCards as $whiteCard) { ?>
            <li><? $this->drawCard($whiteCard); ?></li>
        <? } ?>
    </ul>
</div>
</main>
<footer>
    <div class="wrapper">
        <a href="<?=self::$config['urlPrefix'] . $game->player->token?>" class="submit button" id="submit"><?=translate('submit') ?></a>
    </div>
</footer>