(function() {

  $(function() {

    var selected = [];

    var picks = parseInt($('ul.cards').attr('data-pick'), 10);

    $('a.card').on('click', function() {
      var cid = parseInt($(this).attr('data-id'), 10);
      var willSelect = (selected.indexOf(cid) < 0);

      // already selected enough
      if (willSelect && selected.length >= picks) return false;

      $(this).toggleClass('selected', willSelect);
      $(this).removeAttr('data-pick');

      if (willSelect)
      {
        selected.push(cid);
      } else {
        selected.splice(selected.indexOf(cid), 1);
      }

      if (picks > 1)
      {
        // fix picks
        for(var i = 0; i < selected.length; i++)
        {
          $('a.card[data-id="' + selected[i] + '"]').attr('data-pick', i + 1);
        }
      }

      $('footer').toggleClass('ready', selected.length === picks);
    });

    $('a#submit').on('click', function() {

      $.ajax({
        type: "POST",
        url: $(this).attr('href'),
        data: {'picks[]': selected.join(',')},
        success: function(data) {
          console.log(data);
        },
        contentType: 'application/x-www-form-urlencoded'
      });
      return false;
    });

    var $header = $('header');
    var stuck = false;
    $(window).on('scroll', function() {
      if ($(this).scrollTop() > 0 !== stuck)
      {
        stuck = $(this).scrollTop() > 0;
        $header.toggleClass('stuck', stuck);
      }
    });

  });
})();