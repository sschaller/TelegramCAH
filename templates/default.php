<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">

    <title>Play Cards Against Humanity</title>
    <meta name="description" content="Play Cards Against Humanity">
    <meta name="author" content="blueapricot.ch">
    <link href="https://fonts.googleapis.com/css?family=Roboto:500" rel="stylesheet">
</head>

<body>
<main>
    <header>
        <? $this->drawCard($game->blackCard) ?>
    </header>
    <ul class="cards">
        <? foreach ($game->whiteCards as $whiteCard) { ?>
            <li><? $this->drawCard($whiteCard); ?></li>
        <? } ?>
    </ul>
</main>

<style type="text/css"><?= $less->compileFile('templates/default.less') ?></style>
<script type="application/javascript" src="/cah/templates/jquery-3.3.1.min.js"></script>
<script type="application/javascript" src="/cah/templates/default.js"></script>
</body>
</html>