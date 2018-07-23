<header>

</header>
<main>
    <div class="wrapper">
        <ul class="owl-carousel">
            <? foreach ($players as $player) { ?>
            <li>
                <? foreach ($player->picks as $pick) { ?>
                <div class="card"><?= $pick->content ?></div>
                <? } ?>
            </li>
            <? } ?>
        </ul>
    </div>
</main>
<footer>

</footer>