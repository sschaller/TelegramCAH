<header>
    <div class="wrapper">
    <? $this->drawCard($game->blackCard) ?>
    </div>
</header>
<main>
<div class="wrapper">
    <ul class="cards <?= ($game->player->done || $game->blackCard->player == $game->player->id) ? '' : 'ia'; ?>" data-pick="<?=$game->blackCard->required?>">
        <? foreach ($game->whiteCards as $whiteCard) { ?>
            <li><? $this->drawCard($whiteCard, $game->blackCard->required); ?></li>
        <? } ?>
    </ul>
</div>
</main>
<footer>
    <div class="wrapper">
        <a href="<?=self::$config['urlPrefix'] . 'play/' . $game->player->token?>" class="submit button" id="submit"><?=translate('submit') ?></a>
    </div>
</footer>