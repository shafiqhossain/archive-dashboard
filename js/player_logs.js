(function ($, Drupal, drupalSettings, once) {
  Drupal.behaviors.player_logs = {
    attach: function (context) {

      // Audio play
      once('audio-player-wrapper', 'body', context).forEach(function (wrapper) {
        wrapper.addEventListener('click', function (e) {
          const btn = e.target.closest('.mejs__audio .mejs__play');
          if (btn) {
            const media = btn.closest('.mejs__container')?.querySelector('audio');
            if (media) {
              logEvent('play', 'audio', media);
            }
          }
        });
      });

      // Video play
      once('video-player-wrapper', 'body', context).forEach(function (wrapper) {
        wrapper.addEventListener('click', function (e) {
          const btn =
            e.target.closest('.mejs__video .mejs__play') ||
            e.target.closest('.mejs__video .mejs__overlay-play');

          if (btn) {
            const media = btn.closest('.mejs__container')?.querySelector('video');
            if (media) {
              logEvent('play', 'video', media);
            }
          }
        });
      });

      // Audio / Video download
      once(
        'download-player-wrapper',
        '.archive-description-download-link',
        context
      ).forEach(function (link) {
        link.addEventListener('click', function () {
          logEvent('download', 'file', link);
        });
      });

      /**
       * Log the evet
       */
      function logEvent(action, type, el) {
        const payload = {
          node_id: drupalSettings.custom_example?.node_id || null,
          media_url:
            el.tagName === 'A'
              ? el.getAttribute('href')
              : el.currentSrc,
          action: action,
          type: type,
          position:
            el.currentTime !== undefined
              ? el.currentTime
              : null
        };

        const url = Drupal.url('media-tracking/log');

        if (navigator.sendBeacon) {
          const blob = new Blob(
            [JSON.stringify(payload)],
            { type: 'application/json' }
          );
          navigator.sendBeacon(url, blob);
        }
        else {
          const img = new Image();
          img.src = url + '?' + new URLSearchParams(payload).toString();
        }
      }

    }
  };
})(jQuery, Drupal, drupalSettings, once);
