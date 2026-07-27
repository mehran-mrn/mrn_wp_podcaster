(function () {
  'use strict';

  var root = document.querySelector('[data-mrnp-player]');
  if (!root) {
    return;
  }

  var audio = root.querySelector('[data-mrnp-audio]');
  var toggle = root.querySelector('[data-mrnp-toggle]');
  var seek = root.querySelector('[data-mrnp-seek]');
  var current = root.querySelector('[data-mrnp-current]');
  var duration = root.querySelector('[data-mrnp-duration]');
  var speed = root.querySelector('[data-mrnp-speed]');
  var volume = root.querySelector('[data-mrnp-volume]');
  var source = root.querySelector('[data-mrnp-source]');
  var title = root.querySelector('[data-mrnp-title]');
  var miniTitle = root.querySelector('[data-mrnp-mini-title]');
  var meta = root.querySelector('[data-mrnp-meta]');
  var cover = root.querySelector('[data-mrnp-cover]');
  var download = root.querySelector('[data-mrnp-download]');
  var labels = window.mrnpPlayerConfig ? window.mrnpPlayerConfig.labels : {};
  var episode = null;
  var sourceNames = ['primary', 'backup', 'local'];
  var lastSave = 0;
  var switching = false;

  function storageGet(key) {
    try {
      return window.localStorage.getItem(key);
    } catch (error) {
      return null;
    }
  }

  function storageSet(key, value) {
    try {
      window.localStorage.setItem(key, value);
    } catch (error) {
      // Playback remains functional when storage is blocked.
    }
  }

  function formatTime(value) {
    var seconds = Number.isFinite(value) ? Math.max(0, Math.floor(value)) : 0;
    var hours = Math.floor(seconds / 3600);
    var mins = Math.floor((seconds % 3600) / 60);
    var secs = seconds % 60;
    return hours
      ? hours + ':' + String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0')
      : String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
  }

  function availableSources() {
    return sourceNames.filter(function (name) {
      return episode && episode.sources && episode.sources[name];
    });
  }

  function sourceLabel(name) {
    return labels[name] || name;
  }

  function updateSourceMenu(preferred) {
    source.innerHTML = '';
    availableSources().forEach(function (name) {
      var option = document.createElement('option');
      option.value = name;
      option.textContent = sourceLabel(name);
      option.selected = name === preferred;
      source.appendChild(option);
    });
    source.hidden = source.options.length < 2;
    var sourceLabelElement = source.closest('label');
    if (sourceLabelElement) {
      sourceLabelElement.hidden = source.options.length < 2;
    }
  }

  function saveProgress() {
    if (!episode || !episode.id || !Number.isFinite(audio.currentTime)) {
      return;
    }
    storageSet('mrnp-progress-' + episode.id, String(audio.currentTime));
  }

  function restoreProgress() {
    if (!episode || !episode.id) {
      return;
    }
    var saved = parseFloat(storageGet('mrnp-progress-' + episode.id));
    if (Number.isFinite(saved) && saved > 0 && saved < audio.duration - 10) {
      audio.currentTime = saved;
    }
  }

  function updateMediaSession() {
    if (!('mediaSession' in navigator) || !episode) {
      return;
    }
    var artwork = episode.cover
      ? [{ src: episode.cover, sizes: '512x512' }]
      : [];
    navigator.mediaSession.metadata = new window.MediaMetadata({
      title: episode.title,
      artist: 'MRN Podcaster',
      album: episode.episode ? 'اپیزود ' + episode.episode : 'Podcast',
      artwork: artwork
    });
  }

  function loadSource(name, shouldPlay, keepPosition) {
    if (!episode || !episode.sources || !episode.sources[name]) {
      return;
    }
    var position = keepPosition ? audio.currentTime : 0;
    switching = true;
    source.value = name;
    audio.src = episode.sources[name];
    download.href = episode.sources[name];
    audio.load();
    if (shouldPlay) {
      audio.play().catch(function () {
        updatePlayingState();
      });
    }
    audio.addEventListener('loadedmetadata', function loaded() {
      audio.removeEventListener('loadedmetadata', loaded);
      if (keepPosition && position < audio.duration) {
        audio.currentTime = position;
      } else {
        restoreProgress();
      }
      switching = false;
    });
  }

  function setEpisode(nextEpisode, shouldPlay) {
    if (!nextEpisode || !nextEpisode.id || !nextEpisode.sources) {
      return;
    }
    if (episode && episode.id === nextEpisode.id) {
      if (shouldPlay) {
        audio.paused ? audio.play() : audio.pause();
      }
      return;
    }

    saveProgress();
    episode = nextEpisode;
    title.textContent = episode.title || 'Podcast';
    miniTitle.textContent = episode.title || 'Podcast';
    meta.textContent = episode.episode ? 'اپیزود ' + episode.episode : 'MRN Podcaster';
    cover.src = episode.cover || '';
    cover.hidden = !episode.cover;
    duration.textContent = formatTime(Number(episode.duration || 0));
    root.classList.add('is-ready');
    root.classList.remove('is-closed');
    document.body.classList.add('mrnp-player-active');
    updateSourceMenu('primary');
    updateMediaSession();
    var firstSource = availableSources()[0];
    if (firstSource) {
      loadSource(firstSource, shouldPlay, false);
    }
  }

  function updatePlayingState() {
    var playing = !audio.paused && !audio.ended;
    root.classList.toggle('is-playing', playing);
    toggle.setAttribute('aria-label', playing ? (labels.pause || 'Pause') : (labels.play || 'Play'));
    if ('mediaSession' in navigator) {
      navigator.mediaSession.playbackState = playing ? 'playing' : 'paused';
    }
  }

  function skip(seconds) {
    audio.currentTime = Math.max(0, Math.min(audio.duration || Infinity, audio.currentTime + seconds));
  }

  function tryNextSource() {
    if (switching || !episode) {
      return;
    }
    var names = availableSources();
    var currentIndex = names.indexOf(source.value);
    var next = names[currentIndex + 1];
    if (next) {
      loadSource(next, true, true);
      return;
    }
    meta.textContent = labels.unavailable || 'Audio unavailable';
  }

  toggle.addEventListener('click', function () {
    if (!episode) {
      return;
    }
    audio.paused ? audio.play() : audio.pause();
  });

  root.querySelectorAll('[data-mrnp-skip]').forEach(function (button) {
    button.addEventListener('click', function () {
      skip(parseInt(button.dataset.mrnpSkip, 10) || 0);
    });
  });

  seek.addEventListener('input', function () {
    if (Number.isFinite(audio.duration)) {
      audio.currentTime = (Number(seek.value) / 1000) * audio.duration;
    }
  });

  speed.addEventListener('change', function () {
    audio.playbackRate = Number(speed.value);
    storageSet('mrnp-speed', speed.value);
  });

  volume.addEventListener('input', function () {
    audio.volume = Number(volume.value);
    storageSet('mrnp-volume', volume.value);
  });

  source.addEventListener('change', function () {
    loadSource(source.value, !audio.paused, true);
  });

  root.querySelector('[data-mrnp-minimize]').addEventListener('click', function () {
    root.classList.add('is-minimized');
    storageSet('mrnp-minimized', '1');
  });

  root.querySelector('[data-mrnp-expand]').addEventListener('click', function () {
    root.classList.remove('is-minimized');
    storageSet('mrnp-minimized', '0');
  });

  root.querySelector('[data-mrnp-close]').addEventListener('click', function () {
    audio.pause();
    root.classList.add('is-closed');
    document.body.classList.remove('mrnp-player-active');
  });

  audio.addEventListener('play', updatePlayingState);
  audio.addEventListener('pause', updatePlayingState);
  audio.addEventListener('ended', function () {
    if (episode) {
      storageSet('mrnp-progress-' + episode.id, '0');
    }
    updatePlayingState();
  });
  audio.addEventListener('error', tryNextSource);
  audio.addEventListener('loadedmetadata', function () {
    duration.textContent = formatTime(audio.duration);
  });
  audio.addEventListener('timeupdate', function () {
    current.textContent = formatTime(audio.currentTime);
    if (Number.isFinite(audio.duration) && audio.duration > 0) {
      seek.value = String(Math.round((audio.currentTime / audio.duration) * 1000));
    }
    if (Date.now() - lastSave > 5000) {
      lastSave = Date.now();
      saveProgress();
    }
  });

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-mrnp-play]');
    if (!button) {
      return;
    }
    try {
      setEpisode(JSON.parse(button.dataset.mrnpPlay), true);
    } catch (error) {
      // Ignore malformed third-party markup.
    }
  });

  document.querySelectorAll('[data-mrnp-carousel]').forEach(function (carousel) {
    var track = carousel.querySelector('[data-mrnp-carousel-track]');
    var next = carousel.querySelector('[data-mrnp-carousel-next]');
    var prev = carousel.querySelector('[data-mrnp-carousel-prev]');
    function move(direction) {
      track.scrollBy({ left: direction * Math.max(280, track.clientWidth * 0.72), behavior: 'smooth' });
    }
    if (next) {
      next.addEventListener('click', function () { move(1); });
    }
    if (prev) {
      prev.addEventListener('click', function () { move(-1); });
    }
  });

  if ('mediaSession' in navigator) {
    navigator.mediaSession.setActionHandler('play', function () { audio.play(); });
    navigator.mediaSession.setActionHandler('pause', function () { audio.pause(); });
    navigator.mediaSession.setActionHandler('seekbackward', function (details) { skip(-(details.seekOffset || 15)); });
    navigator.mediaSession.setActionHandler('seekforward', function (details) { skip(details.seekOffset || 30); });
    navigator.mediaSession.setActionHandler('seekto', function (details) {
      if (details.fastSeek && 'fastSeek' in audio) {
        audio.fastSeek(details.seekTime);
      } else {
        audio.currentTime = details.seekTime;
      }
    });
  }

  var storedSpeed = parseFloat(storageGet('mrnp-speed'));
  var storedVolume = parseFloat(storageGet('mrnp-volume'));
  if (Number.isFinite(storedSpeed)) {
    audio.playbackRate = storedSpeed;
    speed.value = String(storedSpeed);
  }
  if (Number.isFinite(storedVolume)) {
    audio.volume = storedVolume;
    volume.value = String(storedVolume);
  }
  if (storageGet('mrnp-minimized') === '1') {
    root.classList.add('is-minimized');
  }

  try {
    var initial = JSON.parse(root.dataset.initial || '{}');
    if (initial && initial.id) {
      setEpisode(initial, false);
    }
  } catch (error) {
    // Empty players wait for an episode card click.
  }

  window.addEventListener('pagehide', saveProgress);
}());
