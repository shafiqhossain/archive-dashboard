(function ($, Drupal, drupalSettings, once) {
  Drupal.behaviors.mediaelementTracking = {
    attach: function (context, settings) {

      // Use the core 'once' utility instead of jQuery once
      once('mediaelement-tracking', 'audio, video', context).forEach(function (mediaElement) {

        const mediaType = mediaElement.tagName.toLowerCase();

        // Track Play
        mediaElement.addEventListener('play', function () {
          logEvent('play', mediaType, mediaElement);
        });

        // Track Download button (MediaElement.js download button)
        const container = mediaElement.closest('.mejs__container');
        if (container) {
          const downloadBtn = container.querySelector('.mejs__download-button > button');
          if (downloadBtn) {
            downloadBtn.addEventListener('click', function () {
              logEvent('download', mediaType, mediaElement);
            });
          }
        }

      });

      function logEvent(action, type, el) {
        const payload = {
          node_id: drupalSettings.custom_example?.node_id || null,
          media_url: el.currentSrc,
          action: action,
          type: type,
          position: el.currentTime
        };

        const url = Drupal.url('media-tracking/log');

        if (navigator.sendBeacon) {
          const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
          navigator.sendBeacon(url, blob);
        }
        else {
          // fallback (image beacon)
          const img = new Image();
          img.src = url + '?' + new URLSearchParams(payload).toString();
        }
      }

    }
  };
})(jQuery, Drupal, drupalSettings, once);
