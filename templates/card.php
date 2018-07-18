<a class="card <?= $card['type'] == CardType::Black ? 'black' : 'white'; ?> <?= $card['pick'] > 0 ? 'selected' : '' ?>"<?=$required > 1 && $card['pick'] > 0 ? ' data-pick="' . $card['pick'] . '"' : '' ?>data-id="<?= $card['id'] ?>">
    <?= $content ?>
</a>