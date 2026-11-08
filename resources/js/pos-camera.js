import { createFrameDecoder, createScanGate } from './pos-camera-decoder.js';

const scanInterval = 125;

function cameraError(error) {
  switch (error.name) {
    case 'NotAllowedError':
    case 'SecurityError':
      return 'Camera access was blocked. Allow camera access in your browser or device settings and retry.';
    case 'NotFoundError':
    case 'OverconstrainedError':
      return 'No matching camera is available. Connect a camera or retry with the default camera.';
    case 'NotReadableError':
    case 'AbortError':
      return 'The camera could not start. Close other apps using it and retry.';
    default:
      return 'Camera scanning could not start. Retry or use the POS search box.';
  }
}

function scanChime(audio) {
  if (audio?.state !== 'running') return;
  const tone = audio.createOscillator();
  const volume = audio.createGain();
  const now = audio.currentTime;
  tone.frequency.value = 740;
  volume.gain.setValueAtTime(0.045, now);
  volume.gain.exponentialRampToValueAtTime(0.001, now + 0.1);
  tone.connect(volume).connect(audio.destination);
  tone.start(now);
  tone.stop(now + 0.1);
}

export function setupCameraScanner(button, dialog, onScan) {
  const preview = dialog.querySelector('[data-camera-preview]');
  const frame = dialog.querySelector('.pos-camera-frame');
  const video = dialog.querySelector('video');
  const status = dialog.querySelector('[data-camera-status]');
  const switchButton = dialog.querySelector('[data-camera-switch]');
  const torchButton = dialog.querySelector('[data-camera-torch]');
  const zoomLabel = dialog.querySelector('[data-camera-zoom]');
  const zoomInput = zoomLabel.querySelector('input');
  const retry = dialog.querySelector('[data-camera-retry]');
  const devices = navigator.mediaDevices;
  let session, audio, accept;

  function feedback(text, type = '') {
    status.textContent = text;
    dialog.dataset.feedback = type;
  }

  function stop() {
    if (session) {
      session.controller.abort();
      clearTimeout(session.timer);
      session.stream?.getTracks().forEach((track) => track.stop());
      session = null;
    }
    video.srcObject = null;
    preview.hidden =
      switchButton.hidden =
      torchButton.hidden =
      zoomLabel.hidden =
      retry.hidden =
        true;
  }

  function cleanup() {
    stop();
    observer.disconnect();
    audio?.close().catch(() => {});
    audio = null;
  }

  function close() {
    cleanup();
    if (dialog.open) dialog.close();
  }

  const observer = new MutationObserver(() => {
    if (!dialog.isConnected) close();
  });

  async function configureCamera(current) {
    const { track, focus, torch, zoom } = current;
    const advanced = [];
    if (focus) advanced.push({ focusMode: 'continuous' });
    if (torch != null) advanced.push({ torch });
    if (zoom != null) advanced.push({ zoom });
    if (advanced.length) await track.applyConstraints({ ...track.getConstraints?.(), advanced });
  }

  async function start(deviceId) {
    stop();
    accept([], performance.now());
    if (!window.isSecureContext) {
      feedback(
        'Camera access requires HTTPS or localhost. Open the secure POS address to scan.',
        'error',
      );
      return;
    }
    if (!devices?.getUserMedia) {
      feedback(
        'Live camera access is unavailable in this browser. Use the POS search box.',
        'error',
      );
      return;
    }

    feedback('Waiting for camera access…');
    const current = { controller: new AbortController() };
    const { signal } = current.controller;
    session = current;
    const active = () => !signal.aborted && dialog.open && dialog.isConnected;
    const fail = (text) => {
      if (!active()) return;
      stop();
      feedback(text, 'error');
      retry.hidden = false;
    };

    try {
      const stream = await devices.getUserMedia({
        audio: false,
        video: {
          width: { ideal: 1920 },
          height: { ideal: 1080 },
          frameRate: { ideal: 24 },
          ...(deviceId
            ? { deviceId: { exact: deviceId } }
            : { facingMode: { ideal: 'environment' } }),
        },
      });
      if (!active()) {
        stream.getTracks().forEach((track) => track.stop());
        return;
      }
      current.stream = stream;
      const [track] = stream.getVideoTracks();
      current.track = track;
      track.addEventListener('ended', () => fail('Camera disconnected. Reconnect it and retry.'), {
        once: true,
      });
      const capabilities = track.getCapabilities?.() ?? {};
      const settings = track.getSettings?.() ?? {};
      const torch = capabilities.torch;
      const zoom = capabilities.zoom;
      current.focus = capabilities.focusMode?.includes('continuous');
      current.torch = torch === true || torch?.includes?.(true) ? false : null;
      current.zoom =
        zoom && Number.isFinite(zoom.min) && Number.isFinite(zoom.max) && zoom.max > zoom.min
          ? (settings.zoom ?? zoom.min)
          : null;

      video.srcObject = stream;
      preview.hidden = false;
      await video.play();
      if (!active()) return;
      if (current.focus) await configureCamera(current).catch(() => {});
      if (!active()) return;
      torchButton.hidden = current.torch == null;
      torchButton.setAttribute('aria-pressed', 'false');
      torchButton.disabled = false;
      zoomLabel.hidden = current.zoom == null;
      if (!zoomLabel.hidden) {
        zoomInput.min = zoom.min;
        zoomInput.max = zoom.max;
        zoomInput.step = zoom.step || 'any';
        zoomInput.value = current.zoom;
        zoomInput.disabled = false;
      }

      devices
        .enumerateDevices?.()
        .then((list) => {
          if (!active()) return;
          const cameraIds = [
            ...new Set(
              list
                .filter(({ kind, deviceId }) => kind === 'videoinput' && deviceId)
                .map(({ deviceId }) => deviceId),
            ),
          ];
          const activeId = settings.deviceId || deviceId;
          const index = cameraIds.indexOf(activeId);
          current.next = cameraIds.length > 1 ? cameraIds[(index + 1) % cameraIds.length] : null;
          switchButton.hidden = !current.next;
        })
        .catch(() => {});
      switchButton.disabled = false;

      const decode = await createFrameDecoder(video, frame);
      if (!active()) return;
      feedback('Ready to scan.');
      let lastFrame = -1;
      async function scanFrame() {
        if (!active()) return;
        try {
          if (
            video.readyState >= 2 &&
            video.currentTime !== lastFrame &&
            !document.querySelector('dialog[open]:not(#camera-scanner)')
          ) {
            lastFrame = video.currentTime;
            const barcodes = await decode();
            if (!active()) return;
            const barcode = accept(barcodes, performance.now());
            if (barcode) {
              feedback('Checking product…');
              switchButton.disabled = true;
              const result = await onScan(barcode, signal);
              if (!active()) return;
              switchButton.disabled = false;
              feedback(result.text, result.added ? 'success' : 'error');
              if (result.added) scanChime(audio);
            }
          }
        } catch {
          fail('Live barcode scanning stopped. Retry or use the POS search box.');
        }
        if (active()) current.timer = setTimeout(scanFrame, scanInterval);
      }
      scanFrame();
    } catch (error) {
      fail(cameraError(error));
    }
  }

  async function setCameraControl(key, value) {
    const current = session;
    if (!current?.track) return;
    const previous = current[key];
    current[key] = value;
    torchButton.disabled = zoomInput.disabled = true;
    try {
      await configureCamera(current);
      const actual = current.track.getSettings?.()[key];
      if (key === 'torch' && typeof actual === 'boolean' && actual !== value) throw new Error();
      if (key === 'zoom' && typeof actual === 'number') current.zoom = actual;
    } catch {
      current[key] = previous;
      if (session === current)
        feedback(`${key === 'torch' ? 'Torch' : 'Zoom'} is unavailable on this camera.`, 'error');
    } finally {
      if (session === current) {
        torchButton.setAttribute('aria-pressed', String(current.torch === true));
        if (current.zoom != null) zoomInput.value = current.zoom;
        torchButton.disabled = zoomInput.disabled = false;
      }
    }
  }

  button.addEventListener('click', () => {
    if (dialog.open) return;
    dialog.showModal();
    accept = createScanGate();
    observer.observe(document.documentElement, { childList: true, subtree: true });
    const Audio = window.AudioContext || window.webkitAudioContext;
    try {
      audio = Audio ? new Audio() : null;
      audio?.resume().catch(() => {});
    } catch {
      audio = null;
    }
    start();
  });
  dialog.querySelector('[data-camera-close]').addEventListener('click', close);
  dialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    close();
  });
  dialog.addEventListener('close', () => {
    if (!dialog.open) cleanup();
  });
  retry.addEventListener('click', () => start());
  switchButton.addEventListener('click', () => {
    if (session?.next) start(session.next);
  });
  torchButton.addEventListener('click', () => setCameraControl('torch', !session?.torch));
  zoomInput.addEventListener('change', () => setCameraControl('zoom', Number(zoomInput.value)));
  window.addEventListener('pagehide', close);
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) close();
  });
}
