<header>
    <div class="wrapper">
    <? $this->drawCard($game->blackCard) ?>
    </div>
</header>
<main>
<div class="wrapper">
    <ul class="cards <?= $game->player->done ? '' : 'ia'; ?>" data-pick="<?=$game->blackCard->required?>">
        <? foreach ($game->whiteCards as $whiteCard) { ?>
            <li><? $this->drawCard($whiteCard, $game->blackCard->required); ?></li>
        <? } ?>
    </ul>
</div>
</main>
<footer class="<?= $game->player->done ? '' : 'ia'; ?>">
    <div class="wrapper">
        <div class="action">
            <a href="<?=self::$config['urlPrefix'] . 'play/' . $game->player->token?>" class="action__submit button" id="submit"><?=translate('submit') ?></a>
            <div class="action__message" id="message"><?=translate('picks_saved')?></div>
        </div>
    </div>
</footer>