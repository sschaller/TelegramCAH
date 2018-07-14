<div class="card <?= $card->type == self::BlackCard ? 'black' : 'white'; ?>" data-id="<?= $card->id ?>">
    <?= $card->text ?>
</div>