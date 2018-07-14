<a class="card <?= $card['type'] == Game::BlackCard ? 'black' : 'white'; ?>" data-id="<?= $card['id'] ?>">
    <?= $content ?>
</a>