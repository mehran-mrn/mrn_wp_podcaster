(function () {
  'use strict';

  function initializeScrollCarousel(container, options) {
    var track = container.querySelector(options.track);
    var items = track ? Array.from(track.querySelectorAll(options.item)) : [];
    var next = container.querySelector(options.next);
    var prev = container.querySelector(options.prev);
    var counter = container.querySelector(options.counter || '[data-unused-counter]');
    var index = 0;
    var timer = null;

    if (!track || items.length < 1) {
      return;
    }

    function updateCounter() {
      if (counter) {
        counter.textContent = (index + 1) + ' / ' + items.length;
      }
    }

    function show(nextIndex, smooth) {
      index = (nextIndex + items.length) % items.length;
      items[index].scrollIntoView({
        behavior: smooth ? 'smooth' : 'auto',
        block: 'nearest',
        inline: 'center'
      });
      updateCounter();
    }

    function startAutoPlay() {
      if (!options.autoPlay || items.length < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
      }
      window.clearInterval(timer);
      timer = window.setInterval(function () {
        show(index + 1, true);
      }, 6500);
    }

    if (next) {
      next.addEventListener('click', function () {
        show(index + 1, true);
        startAutoPlay();
      });
    }
    if (prev) {
      prev.addEventListener('click', function () {
        show(index - 1, true);
        startAutoPlay();
      });
    }
    container.addEventListener('mouseenter', function () { window.clearInterval(timer); });
    container.addEventListener('mouseleave', startAutoPlay);
    container.addEventListener('focusin', function () { window.clearInterval(timer); });
    container.addEventListener('focusout', startAutoPlay);
    updateCounter();
    startAutoPlay();
  }

  document.querySelectorAll('[data-mrnp-carousel]').forEach(function (carousel) {
    initializeScrollCarousel(carousel, {
      track: '[data-mrnp-carousel-track]',
      item: '.mrnp-episode-card',
      next: '[data-mrnp-carousel-next]',
      prev: '[data-mrnp-carousel-prev]',
      autoPlay: false
    });
  });

  document.querySelectorAll('[data-mrnp-listeners]').forEach(function (carousel) {
    initializeScrollCarousel(carousel, {
      track: '[data-mrnp-listeners-track]',
      item: '.mrnp-listener',
      next: '[data-mrnp-listeners-next]',
      prev: '[data-mrnp-listeners-prev]',
      counter: '[data-mrnp-listeners-counter]',
      autoPlay: true
    });
  });

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
  var miniTime = root.querySelector('[data-mrnp-mini-time]');
  var meta = root.querySelector('[data-mrnp-meta]');
  var cover = root.querySelector('[data-mrnp-cover]');
  var speedValue = root.querySelector('[data-mrnp-speed-value]');
  var labels = window.mrnpPlayerConfig ? window.mrnpPlayerConfig.labels : {};
  var episode = null;
  var sourceNames = ['primary', 'backup', 'local'];
  var speedValues = [0.75, 1, 1.25, 1.5, 1.75, 2];
  var lastSave = 0;
  var switching = false;
  var playRequested = false;

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
    audio.load();
    if (shouldPlay) {
      requestPlay();
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
        audio.paused ? requestPlay() : audio.pause();
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

  function requestPlay() {
    playRequested = true;
    root.classList.add('is-loading');
    audio.play().catch(function () {
      playRequested = false;
      root.classList.remove('is-loading');
      updatePlayingState();
    });
  }

  function updateSpeed(value) {
    var nextSpeed = Number(value);
    audio.playbackRate = speedValues.includes(nextSpeed) ? nextSpeed : 1;
    speed.value = String(audio.playbackRate);
    speedValue.textContent = audio.playbackRate + '×';
    speed.setAttribute('aria-label', 'تغییر سرعت پخش؛ سرعت فعلی ' + audio.playbackRate + ' برابر');
    storageSet('mrnp-speed', String(audio.playbackRate));
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
    playRequested = false;
    root.classList.remove('is-loading');
    meta.textContent = labels.unavailable || 'Audio unavailable';
  }

  toggle.addEventListener('click', function () {
    if (!episode) {
      return;
    }
    audio.paused ? requestPlay() : audio.pause();
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

  speed.addEventListener('click', function () {
    var index = speedValues.indexOf(audio.playbackRate);
    updateSpeed(speedValues[(index + 1) % speedValues.length]);
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
    playRequested = false;
    audio.pause();
    root.classList.remove('is-loading');
    root.classList.add('is-closed');
    document.body.classList.remove('mrnp-player-active');
  });

  audio.addEventListener('play', updatePlayingState);
  audio.addEventListener('pause', function () {
    playRequested = false;
    root.classList.remove('is-loading');
    updatePlayingState();
  });
  audio.addEventListener('playing', function () {
    playRequested = false;
    root.classList.remove('is-loading');
    updatePlayingState();
  });
  audio.addEventListener('waiting', function () {
    if (playRequested || !audio.paused) {
      root.classList.add('is-loading');
    }
  });
  audio.addEventListener('stalled', function () {
    if (playRequested || !audio.paused) {
      root.classList.add('is-loading');
    }
  });
  audio.addEventListener('canplay', function () {
    if (!playRequested) {
      root.classList.remove('is-loading');
    }
  });
  audio.addEventListener('ended', function () {
    if (episode) {
      storageSet('mrnp-progress-' + episode.id, '0');
    }
    updatePlayingState();
  });
  audio.addEventListener('error', tryNextSource);
  audio.addEventListener('loadedmetadata', function () {
    duration.textContent = formatTime(audio.duration);
    miniTime.textContent = formatTime(audio.currentTime) + ' / ' + formatTime(audio.duration);
  });
  audio.addEventListener('timeupdate', function () {
    current.textContent = formatTime(audio.currentTime);
    miniTime.textContent = formatTime(audio.currentTime) + ' / ' + formatTime(audio.duration);
    if (Number.isFinite(audio.duration) && audio.duration > 0) {
      seek.value = String(Math.round((audio.currentTime / audio.duration) * 1000));
      seek.style.setProperty('--mrnp-progress', (Number(seek.value) / 10) + '%');
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
    updateSpeed(storedSpeed);
  } else {
    updateSpeed(1);
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
