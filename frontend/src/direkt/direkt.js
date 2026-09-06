/**
 * Eine fremde Website bearbeiten.
 *
 * Die Seite läuft in einem Rahmen mit gleicher Herkunft wie diese Seite.
 * Deshalb greift dieses Skript direkt in das Dokument darin - es muss
 * nichts in die Kundendatei eingeschleust werden, und beim Speichern
 * bleibt keine Spur der Bearbeitung zurück.
 *
 * Was hier absichtlich fehlt: Abschnitte verschieben, neue einsetzen,
 * Vorlagen tauschen. Dafür braucht es das Datenmodell, und das hat eine
 * fremde Website nicht. Was sie hat, sind Texte und Bilder - und genau
 * die lassen sich ändern.
 */

import './direkt.css';

const daten = JSON.parse(document.getElementById('wa-direkt-daten')?.textContent ?? '{}');

const wurzel = document.querySelector('[data-direkt]');
const rahmen = wurzel?.querySelector('[data-direkt-rahmen]');
const stand = wurzel?.querySelector('[data-direkt-stand]');
const speichernKnopf = wurzel?.querySelector('[data-direkt-speichern]');
const seitenwahl = wurzel?.querySelector('[data-direkt-seite]');
const bildfeld = wurzel?.querySelector('[data-direkt-bildfeld]');

let geaendert = false;
let seite = daten.seite ?? '';
let bildZiel = null;

/** Was das Skript in die Seite schreibt, damit es sich wieder ausräumen lässt. */
const MARKE = 'data-wa-direkt';

function melden(text, art = 'ruhig') {
  if (!stand) return;
  stand.textContent = text;
  stand.dataset.art = art;
}

function markieren() {
  geaendert = true;
  if (speichernKnopf) speichernKnopf.disabled = false;
  melden('nicht gespeichert', 'offen');
}

/**
 * Taugt dieses Element als Textfeld?
 *
 * Nur Elemente, die ausschliesslich Text enthalten. Ein <div>, in dem
 * zehn andere Elemente liegen, wäre editierbar - und ein unbedachter
 * Tastendruck darin zerlegte die halbe Seite.
 */
function nurText(el) {
  if (!el || el.nodeType !== 1) return false;
  if (el.children.length > 0) return false;
  const text = (el.textContent ?? '').trim();
  if (text === '') return false;
  return !['SCRIPT', 'STYLE', 'TITLE', 'NOSCRIPT'].includes(el.tagName);
}

/** Die Seite im Rahmen zum Bearbeiten herrichten. */
function herrichten(dok) {
  if (!dok || !dok.body) return;

  // Verweise gehen beim Bearbeiten nirgendwohin: Ein Klick auf ein
  // Menü führte sonst aus der Seite heraus, mitten in die Arbeit.
  dok.addEventListener('click', (e) => {
    const verweis = e.target.closest?.('a');
    if (verweis) e.preventDefault();
  }, true);

  dok.addEventListener('submit', (e) => e.preventDefault(), true);

  // Ein Stil, der zeigt, was sich anfassen lässt. Er trägt die Marke
  // und fliegt vor dem Speichern wieder hinaus.
  const stil = dok.createElement('style');
  stil.setAttribute(MARKE, '');
  stil.textContent = `
    [contenteditable="true"] { outline: 2px dashed rgba(139,133,255,.9); outline-offset: 2px; }
    [${MARKE}-hover] { outline: 2px dashed rgba(47,224,220,.85); outline-offset: 2px; cursor: text; }
    img[${MARKE}-bild] { outline: 2px dashed rgba(47,224,220,.85); outline-offset: 2px; cursor: pointer; }
  `;
  dok.head?.appendChild(stil);

  dok.addEventListener('mouseover', (e) => {
    const ziel = e.target;
    if (ziel?.tagName === 'IMG') { ziel.setAttribute(`${MARKE}-bild`, ''); return; }
    if (nurText(ziel)) ziel.setAttribute(`${MARKE}-hover`, '');
  });

  dok.addEventListener('mouseout', (e) => {
    e.target?.removeAttribute?.(`${MARKE}-hover`);
    e.target?.removeAttribute?.(`${MARKE}-bild`);
  });

  dok.addEventListener('click', (e) => {
    const ziel = e.target;

    if (ziel?.tagName === 'IMG') {
      bildZiel = ziel;
      bildfeld?.click();
      return;
    }

    if (!nurText(ziel)) return;

    ziel.setAttribute('contenteditable', 'true');
    ziel.focus();
  });

  dok.addEventListener('input', markieren, true);

  dok.addEventListener('blur', (e) => {
    e.target?.removeAttribute?.('contenteditable');
  }, true);
}

/**
 * Die Seite so serialisieren, wie sie ohne Bearbeitung aussähe.
 *
 * Auf einer Kopie, nicht am Original: Wer die Marken aus dem laufenden
 * Dokument entfernt, nimmt dem Bearbeiter mitten in der Arbeit sein
 * Werkzeug weg.
 */
function alsHtml(dok) {
  const kopie = dok.documentElement.cloneNode(true);

  kopie.querySelectorAll(`[${MARKE}]`).forEach((el) => el.remove());
  kopie.querySelectorAll('[contenteditable]').forEach((el) => el.removeAttribute('contenteditable'));
  kopie.querySelectorAll(`[${MARKE}-hover]`).forEach((el) => el.removeAttribute(`${MARKE}-hover`));
  kopie.querySelectorAll(`[${MARKE}-bild]`).forEach((el) => el.removeAttribute(`${MARKE}-bild`));

  // contenteditable hinterlaesst in manchen Browsern ein leeres
  // style-Attribut. Es tut nichts - aber es steht danach in der Datei
  // des Kunden, und was nicht hineingehoert, bleibt auch nicht drin.
  kopie.querySelectorAll('[style=""]').forEach((el) => el.removeAttribute('style'));

  const typ = dok.doctype ? `<!DOCTYPE ${dok.doctype.name}>\n` : '';

  return typ + kopie.outerHTML;
}

async function anfrage(url, koerper) {
  const antwort = await fetch(url, {
    method: 'POST',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': daten.token ?? '' },
    body: koerper,
  });

  const inhalt = await antwort.json().catch(() => ({}));

  if (!antwort.ok || inhalt.ok === false) {
    throw new Error(inhalt.error ?? `Die Anfrage ist fehlgeschlagen (${antwort.status}).`);
  }

  return inhalt;
}

async function speichern() {
  const dok = rahmen?.contentDocument;
  if (!dok) return;

  melden('speichert …');
  if (speichernKnopf) speichernKnopf.disabled = true;

  const formular = new FormData();
  formular.append('seite', seite);
  formular.append('inhalt', alsHtml(dok));
  formular.append('_token', daten.token ?? '');

  try {
    await anfrage(`${daten.base}/direkt/${daten.id}/speichern`, formular);
    geaendert = false;
    melden('gespeichert', 'gut');
  } catch (fehler) {
    melden(fehler.message, 'schlecht');
    if (speichernKnopf) speichernKnopf.disabled = false;
  }
}

async function bildTauschen(datei) {
  if (!bildZiel || !datei) return;

  melden('Bild wird eingesetzt …');

  const formular = new FormData();
  formular.append('bild', datei);
  formular.append('_token', daten.token ?? '');

  try {
    const antwort = await anfrage(`${daten.base}/direkt/${daten.id}/bild`, formular);

    // Der Pfad steht ab der Wurzel des Stands - relativ zur gerade
    // gezeigten Seite muss er ebenso viele Ebenen zurueckgehen.
    const tiefe = seite.split('/').length - 1;
    bildZiel.setAttribute('src', '../'.repeat(tiefe) + antwort.pfad);
    bildZiel.removeAttribute('srcset');
    markieren();
  } catch (fehler) {
    melden(fehler.message, 'schlecht');
  }

  bildZiel = null;
}

// ------------------------------------------------------------------ Anschluss

if (rahmen) {
  rahmen.addEventListener('load', () => {
    herrichten(rahmen.contentDocument);
    if (!geaendert) melden('bereit');
  });

  speichernKnopf?.addEventListener('click', speichern);

  bildfeld?.addEventListener('change', () => {
    bildTauschen(bildfeld.files?.[0]);
    bildfeld.value = '';
  });

  seitenwahl?.addEventListener('change', () => {
    if (geaendert && !window.confirm('Diese Seite ist nicht gespeichert. Trotzdem wechseln?')) {
      seitenwahl.value = seite;
      return;
    }

    seite = seitenwahl.value;
    geaendert = false;
    if (speichernKnopf) speichernKnopf.disabled = true;
    rahmen.src = `${daten.base}/direkt/${daten.id}/datei/${seite}`;
  });

  // Wer den Rahmen verlässt, ohne zu speichern, verliert die Arbeit.
  window.addEventListener('beforeunload', (e) => {
    if (!geaendert) return;
    e.preventDefault();
    e.returnValue = '';
  });

  // Strg+S ist der Griff, den jeder kennt.
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      if (geaendert) speichern();
    }
  });
}
