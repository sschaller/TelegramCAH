(function() {

  $(function() {

    $('a.card').on('click', function() {
      $(this).toggleClass('selected');
    });

  });
})();