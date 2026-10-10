const library = document.querySelector('#documentation-library');
if (library) {
  const search = library.querySelector('#documentation-search');
  const categories = [...library.querySelectorAll('[data-documentation-category]')];
  const cards = [...library.querySelectorAll('[data-documentation-card]')];
  let category = '';
  const filter = () => {
    const words = search.value.toLocaleLowerCase().trim().split(/\s+/).filter(Boolean);
    let count = 0;
    cards.forEach((card) => {
      const matches =
        (!category || category === card.dataset.category) &&
        words.every((word) => card.dataset.search.includes(word));
      card.hidden = !matches;
      if (matches) count++;
    });
    library.querySelector('#documentation-result-count').textContent =
      `${count} ${count === 1 ? 'guide' : 'guides'}`;
    library.querySelector('#documentation-no-results').hidden = count !== 0;
    categories.forEach((button) => {
      const selected = button.dataset.documentationCategory === category;
      button.classList.toggle('selected', selected);
      button.setAttribute('aria-pressed', String(selected));
    });
  };
  search.addEventListener('input', filter);
  categories.forEach((button) =>
    button.addEventListener('click', () => {
      category = button.dataset.documentationCategory;
      filter();
    }),
  );
  library.querySelector('#documentation-clear').addEventListener('click', () => {
    search.value = '';
    category = '';
    filter();
    search.focus();
  });
  document.addEventListener('keydown', (event) => {
    if (
      event.key === '/' &&
      !event.ctrlKey &&
      !event.metaKey &&
      !event.altKey &&
      !event.target.closest('input, textarea, select, [contenteditable]')
    ) {
      event.preventDefault();
      search.focus();
    }
  });
}

const guide = document.querySelector('#documentation-guide');
if (guide) {
  const scenes = [...guide.querySelectorAll('[data-demo-scene]')];
  const written = [...guide.querySelectorAll('[data-written-step]')];
  const seekButtons = [...guide.querySelectorAll('[data-demo-seek]')];
  const demo = guide.querySelector('#documentation-demo');
  const playButton = guide.querySelector('[data-demo-play]');
  const previous = guide.querySelector('[data-demo-previous]');
  const next = guide.querySelector('[data-demo-next]');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const frameDuration = 3500;
  let index = 0;
  let frameIndex = 0;
  let playing = false;
  let singleStep = false;
  let timer;
  let startedAt;
  let remaining = frameDuration;
  let finished = false;
  const stopTimer = (keepTime = false) => {
    if (keepTime && timer !== undefined) {
      remaining = Math.max(0, remaining - (performance.now() - startedAt));
    }
    window.clearTimeout(timer);
    timer = undefined;
  };
  const frames = () => [...scenes[index].querySelectorAll('[data-demo-frame]')];
  const render = () => {
    scenes.forEach((scene, number) => {
      scene.hidden = number !== index;
    });
    written.forEach((item, number) => item.classList.toggle('current', number === index));
    seekButtons.forEach((button, number) => {
      button.classList.toggle('selected', number === index);
      if (number === index) button.setAttribute('aria-current', 'step');
      else button.removeAttribute('aria-current');
    });
    frames().forEach((frame, number) => {
      frame.hidden = number !== frameIndex;
    });
    guide.querySelector('#documentation-demo-step').textContent =
      `Step ${index + 1} of ${scenes.length}`;
    guide.querySelector('#documentation-demo-caption').textContent =
      written[index].querySelector('h3').textContent;
    previous.disabled = index === 0;
    next.disabled = index === scenes.length - 1;
    playButton.textContent = playing ? 'Pause example' : 'Play example';
    demo.classList.toggle('is-playing', playing);
    demo.classList.toggle('is-paused', !playing);
    const frame = frames()[frameIndex];
    if (playing && !frame.classList.contains('has-started')) {
      frame.getBoundingClientRect();
      window.requestAnimationFrame(() => {
        if (frame === frames()[frameIndex] && playing) frame.classList.add('has-started');
      });
    }
  };
  const schedule = () => {
    stopTimer();
    if (!playing) return;
    startedAt = performance.now();
    timer = window.setTimeout(() => {
      timer = undefined;
      remaining = frameDuration;
      if (frameIndex < frames().length - 1) {
        frameIndex++;
        render();
        schedule();
      } else if (singleStep || index === scenes.length - 1) {
        playing = false;
        finished = true;
        render();
      } else show(index + 1);
    }, remaining);
  };
  const show = (step) => {
    stopTimer();
    index = Math.max(0, Math.min(step, scenes.length - 1));
    frameIndex = 0;
    finished = false;
    remaining = frameDuration;
    frames().forEach((frame) => frame.classList.remove('has-started'));
    render();
    schedule();
  };
  const choose = (step) => {
    singleStep = true;
    playing = !reducedMotion.matches && !document.hidden;
    show(step);
  };
  guide.querySelectorAll('[data-watch-step]').forEach((button) => {
    button.disabled = false;
    button.addEventListener('click', () => {
      choose(Number(button.dataset.watchStep));
      if (window.matchMedia('(max-width: 1000px)').matches) {
        guide.querySelector('.documentation-player').scrollIntoView({
          behavior: reducedMotion.matches ? 'instant' : 'smooth',
          block: 'start',
        });
      }
    });
  });
  seekButtons.forEach((button) => {
    button.disabled = false;
    button.addEventListener('click', () => choose(Number(button.dataset.demoSeek)));
  });
  previous.addEventListener('click', () => choose(index - 1));
  next.addEventListener('click', () => choose(index + 1));
  playButton.disabled = false;
  playButton.addEventListener('click', () => {
    if (playing) {
      stopTimer(true);
      playing = false;
      render();
    } else {
      playing = true;
      // A finished example starts again; a paused frame resumes where it stopped.
      if (finished) {
        singleStep = false;
        show(index === scenes.length - 1 ? 0 : index);
      } else {
        render();
        schedule();
      }
    }
  });
  const replay = guide.querySelector('[data-demo-replay]');
  replay.disabled = false;
  replay.addEventListener('click', () => {
    singleStep = false;
    playing = true;
    show(0);
  });
  const overview = guide.querySelector('[data-demo-overview]');
  overview.disabled = false;
  overview.addEventListener('click', () => {
    const fullScreen = demo.classList.toggle('show-overview');
    overview.setAttribute('aria-pressed', String(fullScreen));
    overview.textContent = fullScreen ? 'Zoom to control' : 'Show full screen';
  });
  const player = guide.querySelector('.documentation-player');
  const dialog = guide.querySelector('#documentation-expanded');
  const anchor = document.createComment('walkthrough player');
  player.before(anchor);
  const expand = guide.querySelector('[data-demo-expand]');
  // Moving the player into a dialog can restart CSS animations. Keep each
  // animation at its current position, including when the example is paused.
  const animationPositions = () =>
    player.getAnimations({ subtree: true }).map((animation) => ({
      target: animation.effect.target,
      name: animation.animationName,
      time: animation.currentTime,
    }));
  const movePlayer = (move, positions = animationPositions()) => {
    move();
    positions.forEach(({ target, name, time }) => {
      const animation = target.getAnimations().find((item) => item.animationName === name);
      if (animation && time !== null) animation.currentTime = time;
    });
  };
  expand.disabled = false;
  expand.setAttribute('aria-controls', dialog.id);
  expand.setAttribute('aria-expanded', 'false');
  expand.addEventListener('click', () => {
    movePlayer(() => {
      dialog.querySelector('[data-demo-expanded-host]').append(player);
      dialog.showModal();
    });
    expand.hidden = true;
    expand.setAttribute('aria-expanded', 'true');
  });
  let closingPositions = [];
  const closeDialog = () => {
    closingPositions = animationPositions();
    dialog.close();
  };
  guide.querySelector('[data-demo-collapse]').addEventListener('click', closeDialog);
  dialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    closeDialog();
  });
  dialog.addEventListener('close', () => {
    movePlayer(() => anchor.after(player), closingPositions);
    closingPositions = [];
    expand.hidden = false;
    expand.setAttribute('aria-expanded', 'false');
    expand.focus({ preventScroll: true });
  });
  guide.querySelector('[data-documentation-print]').addEventListener('click', () => window.print());
  const pause = () => {
    stopTimer(true);
    playing = false;
    render();
  };
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) pause();
  });
  reducedMotion.addEventListener('change', () => {
    if (reducedMotion.matches) pause();
  });
  const linkedStep = Number(location.hash.match(/^#step-(\d+)$/)?.[1]);
  if (linkedStep > 0 && linkedStep <= scenes.length) index = linkedStep - 1;
  playing = !reducedMotion.matches && !document.hidden && !linkedStep;
  show(index);
}
