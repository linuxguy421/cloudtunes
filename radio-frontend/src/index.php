<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>CloudTunes Radio</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = { darkMode: 'class' };
  </script>
  <link href="dist/skin/plyr/plyr.css" rel="stylesheet" />
  <script src="lib/jquery.min.js"></script>
  <style>
    .spinner {
      border: 4px solid rgba(255, 255, 255, 0.3);
      border-top: 4px solid #3498db;
      border-radius: 50%;
      width: 36px;
      height: 36px;
      animation: spin 1s linear infinite;
      margin: auto;
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
  </style>
  <script>
    function toggleTheme() {
      document.documentElement.classList.toggle('dark');
      const theme = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
      localStorage.setItem('theme', theme);
    }

    window.onload = function () {
      const savedTheme = localStorage.getItem('theme');
      if (savedTheme === 'dark') {
        document.documentElement.classList.add('dark');
      }

      fetchTrackInfo();
      setInterval(fetchTrackInfo, 15000);
    };

    let previousUrl = '';

    function fetchTrackInfo() {
      $.get('albumart.php', function (url) {
        if (url !== previousUrl) {
          previousUrl = url;
          const $box = $('#now-playing-box');
          $box.html(`
            <div class="w-full h-full relative bg-cover bg-center rounded-xl shadow-lg overflow-hidden flex items-end" style="background-image: url('${url}');">
              <div class="w-full bg-white/80 dark:bg-black/60 backdrop-blur-sm p-4 text-center text-sm">
                <div id="plyr-nowplaying" class="plyr-title">Loading track info...</div>
              </div>
            </div>
          `);
          $('#plyr-nowplaying').load('streaminfo.php');
        } else {
          $('#plyr-nowplaying').load('streaminfo.php');
        }
      });
    }
  </script>
</head>

<body class="bg-white dark:bg-gray-900 text-gray-900 dark:text-white min-h-screen p-6 transition-colors duration-300">
  <div class="max-w-xl mx-auto space-y-6">
    <!-- Theme Toggle -->
    <div class="flex justify-end">
      <button onclick="toggleTheme()" class="px-4 py-2 rounded-lg border dark:border-gray-700 hover:bg-gray-200 dark:hover:bg-gray-800 transition">
        Toggle Theme
      </button>
    </div>

    <!-- Audio Player -->
    <div class="bg-gray-100 dark:bg-gray-800 rounded-xl shadow-lg p-4">
      <audio id="player" controls class="w-full">
        <source src="http://10.10.1.128:30420/default0.ogg" type="audio/ogg" />
        Your browser does not support the audio element.
      </audio>
    </div>

    <!-- Now Playing (Album Art + Track Info) -->
    <div id="now-playing-box" class="rounded-xl overflow-hidden aspect-square bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
      <div class="spinner"></div>
    </div>
  </div>
</body>
</html>
