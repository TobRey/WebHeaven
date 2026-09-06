/**
 * Eine fremde Website bearbeiten.
 *
 * Die Seite läuft in einem Rahmen mit gleicher Herkunft wie diese Seite.
 * Deshalb greift dieses Skript direkt in das Dokument darin - es muss
 * nichts in die Kundendatei eingeschleust werden, und beim Speichern
 * bleibt keine Spur der Bearbeitung zurück.
 *
 * Was hier möglich ist: Texte ändern, Bilder tauschen, Blöcke die
 * Plätze tauschen lassen, neue Bausteine einsetzen, Hintergrund und
 * Abstände einstellen.
 *
 * Was bewusst fehlt: alles, was das Erscheinungsbild der ganzen Website
 * verstellt - Schriftart, Zeilenhöhe, Farbschema. Wer das je Element
 * ändert, bekommt eine Website, die an fünf Stellen anders aussieht als
 * an den übrigen.
 *
 * ## Die Regel, an der alles hängt
 *
 * Was das Werkzeug in die Seite schreibt, trägt ein Attribut, das mit
 * `data-wa-` beginnt - und **alles** davon fliegt vor dem Speichern
 * hinaus. Nicht eine gepflegte Liste einzelner Namen: Die vergisst man
 * beim nächsten Werkzeug, und dann steht sie in der Datei des Kunden.
 */

import './direkt.css';
import { greifbar, tauschen, schieben } from './greifen.js';
import { BAUSTEINE, bauen, willBild } from './bausteine.js';
import {
  FELDER, lesen, setzen, zuruecksetzen, hatEigenes,
  alsHexfarbe, alsZahl, alsBildpfad,
} from './einstellungen.js';

const daten = JSON.parse(document.getElementById('wa-direkt-daten')?.textContent ?? '{}');

const wurzel = document.querySelector('[data-direkt]');
const rahmen = wurzel?.querySelector('[data-direkt-rahmen]');
const stand = wurzel?.querySelector('[data-direkt-stand]');
const speichernKnopf = wurzel?.querySelector('[data-direkt-speichern]');
const seitenwahl = wurzel?.querySelector('[data-direkt-seite]');
const bildfeld = wurzel?.querySelector('[data-direkt-bildfeld]');

let geaendert = false;
let seite = daten.seite ?? '';
let bildZiel = null;      // das <img>, das getauscht wird
let bildFuerStil = null;  // oder das Element, dessen Hintergrund kommt
let gewaehlt = null;
let leisteOffen = true;

/** Was das Skript in die Seite schreibt, damit es sich wieder ausräumen lässt. */
const MARKE = 'data-wa-direkt';

/** Woran ein weggeblendeter PHP-Block zu erkennen ist (siehe Build\Maske). */
const PHP_MARKE = 'wa-php-';

// ------------------------------------------------------------------ Bausteine

/** Ein Element bauen - im Hauptdokument, für die Hülle. */
function el(tag, klasse, kinder) {
  const knoten = document.createElement(tag);
  if (klasse) knoten.className = klasse;
  (kinder || []).forEach((kind) => {
    knoten.appendChild(typeof kind === 'string' ? document.createTextNode(kind) : kind);
  });
  return knoten;
}

function knopf(beschriftung, klasse, tut, titel) {
  const b = el('button', klasse, [beschriftung]);
  b.type = 'button';
  if (titel) b.title = titel;
  b.addEventListener('click', tut);
  return b;
}

function feld(beschriftung, eingabe) {
  const kennung = 'wd-' + Math.random().toString(36).slice(2, 9);
  eingabe.id = kennung;
  const marke = el('label', 'wad-feld__marke', [beschriftung]);
  marke.htmlFor = kennung;
  return el('div', 'wad-feld', [marke, eingabe]);
}

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

// ------------------------------------------------------------------ Auswahl

/**
 * Taugt dieses Element als Textfeld?
 *
 * Nur Elemente, die ausschliesslich Text enthalten. Ein <div>, in dem
 * zehn andere Elemente liegen, wäre editierbar - und ein unbedachter
 * Tastendruck darin zerlegte die halbe Seite.
 *
 * Und keines, in dem ein weggeblendeter PHP-Block steckt: Dessen
 * Platzhalter ist ein Kommentarknoten, den das Bearbeiten mitlöschen
 * würde. Was danach in der Datei fehlte, wäre Code des Kunden.
 */
function nurText(el) {
  if (!el || el.nodeType !== 1) return false;
  if (el.children.length > 0) return false;
  if (hatPhp(el)) return false;
  const text = (el.textContent ?? '').trim();
  if (text === '') return false;
  return !['SCRIPT', 'STYLE', 'TITLE', 'NOSCRIPT'].includes(el.tagName);
}

/** Steckt in diesem Element ein weggeblendeter PHP-Block? */
function hatPhp(el) {
  for (const knoten of el.childNodes) {
    if (knoten.nodeType === 8 && (knoten.nodeValue ?? '').startsWith(PHP_MARKE)) return true;
  }
  return false;
}

/**
 * Der Block, den ein Klick meint.
 *
 * Nicht das getroffene Element, sondern der nächste, der sich sinnvoll
 * bewegen lässt. Ein Klick auf ein Wort in einem Absatz meint den
 * Absatz, nicht das <em> darin.
 */
function blockZu(el) {
  let n = el;
  while (n && n.nodeType === 1 && n !== n.ownerDocument.body) {
    if (n.offsetWidth > 0 || n.offsetHeight > 0) return n;
    n = n.parentElement;
  }
  return null;
}

/** Alle Blöcke, die als Ziel eines Zuges taugen. */
function kandidaten() {
  const dok = rahmen?.contentDocument;
  if (!dok?.body) return [];

  return [...dok.body.querySelectorAll('*')].filter((e) => {
    if (['SCRIPT', 'STYLE', 'BR', 'HEAD', 'META', 'LINK'].includes(e.tagName)) return false;
    if (e.hasAttribute(MARKE)) return false;
    const k = e.getBoundingClientRect();
    return k.width > 12 && k.height > 12;
  });
}

function waehlen(el) {
  abwaehlen();
  if (!el) return;

  gewaehlt = el;
  el.setAttribute(`${MARKE}-wahl`, '');
  werkzeugeZeigen();
  blattZeigen();
}

function abwaehlen() {
  const dok = rahmen?.contentDocument;
  dok?.querySelectorAll(`[${MARKE}-wahl]`).forEach((e) => e.removeAttribute(`${MARKE}-wahl`));
  gewaehlt = null;
  document.querySelector('.wad-werkzeuge')?.remove();
  document.querySelector('.wad-blatt')?.remove();
}

// ------------------------------------------------------------------ Werkzeuge

/**
 * Die Werkzeugleiste zum gewählten Block.
 *
 * Sie liegt im Adminbereich und schwebt über dem Rahmen - nicht im
 * Kundendokument. Das ist keine Feinheit: Was nicht in der Datei ist,
 * kann beim Speichern auch nicht darin vergessen werden.
 */
function werkzeugeZeigen() {
  document.querySelector('.wad-werkzeuge')?.remove();
  if (!gewaehlt) return;

  const leiste = el('div', 'wad-werkzeuge');

  const griff = el('span', 'wad-werkzeuge__griff', ['⠿']);
  griff.title = 'Ziehen und auf einen anderen Block fallen lassen – die beiden tauschen';
  leiste.appendChild(griff);

  leiste.appendChild(el('span', 'wad-werkzeuge__name', [gewaehlt.tagName.toLowerCase()]));

  leiste.appendChild(knopf('↑', 'wad-werkzeuge__knopf', () => {
    if (schieben(gewaehlt, -1)) { markieren(); werkzeugeStellen(); }
  }, 'Nach oben'));

  leiste.appendChild(knopf('↓', 'wad-werkzeuge__knopf', () => {
    if (schieben(gewaehlt, 1)) { markieren(); werkzeugeStellen(); }
  }, 'Nach unten'));

  leiste.appendChild(knopf('⤒', 'wad-werkzeuge__knopf', () => {
    const eltern = gewaehlt.parentElement;
    if (eltern && eltern !== eltern.ownerDocument.body) waehlen(eltern);
  }, 'Das umgebende Element wählen'));

  leiste.appendChild(knopf('⧉', 'wad-werkzeuge__knopf', () => {
    const kopie = gewaehlt.cloneNode(true);
    gewaehlt.after(kopie);
    markieren();
    waehlen(kopie);
  }, 'Verdoppeln'));

  leiste.appendChild(knopf('🗑', 'wad-werkzeuge__knopf wad-werkzeuge__knopf--gefahr', () => {
    // Ein Block mit PHP darin geht nicht: Sein Code stünde danach
    // nicht mehr in der Datei, und das merkt man erst beim Kunden.
    if (gewaehlt.querySelector('*') === null && hatPhp(gewaehlt)) {
      melden('Darin steckt PHP – nicht gelöscht.', 'schlecht');
      return;
    }
    if (!window.confirm('Diesen Block löschen?')) return;
    gewaehlt.remove();
    abwaehlen();
    markieren();
  }, 'Löschen'));

  document.body.appendChild(leiste);
  werkzeugeStellen();

  griff.addEventListener('pointerdown', (e) => {
    // Der Zug beginnt im Rahmen - dorthin wird der Zeiger gereicht.
    e.preventDefault();
    zugAusLeiste(gewaehlt, e);
  });
}

/** Die Leiste an das gewählte Element heften. */
function werkzeugeStellen() {
  const leiste = document.querySelector('.wad-werkzeuge');
  if (!leiste || !gewaehlt || !rahmen) return;

  const r = rahmen.getBoundingClientRect();
  const k = gewaehlt.getBoundingClientRect();

  // Über dem Block, mit Abstand - sonst deckt sie dessen erste Zeile
  // zu, und man bearbeitet blind. Ist oben kein Platz, darunter.
  const oben = k.top > 40 ? r.top + k.top - 38 : r.top + k.bottom + 6;

  leiste.style.transform = `translate(${Math.max(r.left + 4, r.left + k.left)}px, ${oben}px)`;
  leiste.hidden = k.bottom < 0 || k.top > r.height;
}

// ------------------------------------------------------------------ Einstellungen

/** Das Einstellungsblatt zum gewählten Block. */
function blattZeigen() {
  document.querySelector('.wad-blatt')?.remove();
  if (!gewaehlt) return;

  const blatt = el('div', 'wad-blatt');

  const kopf = el('div', 'wad-blatt__kopf');
  kopf.appendChild(el('strong', null, [gewaehlt.tagName.toLowerCase()]));
  kopf.appendChild(knopf('×', 'wad-blatt__zu', () => abwaehlen(), 'Schliessen'));
  blatt.appendChild(kopf);

  const koerper = el('div', 'wad-blatt__koerper');

  FELDER.forEach((gruppe) => {
    const g = el('section', 'wad-gruppe');
    g.appendChild(el('h3', 'wad-gruppe__titel', [gruppe.gruppe]));
    gruppe.felder.forEach((f) => g.appendChild(bedienelement(f)));
    koerper.appendChild(g);
  });

  const fuss = el('section', 'wad-gruppe');
  fuss.appendChild(knopf('Einstellungen zurücksetzen', 'wad-knopf', () => {
    zuruecksetzen(gewaehlt);
    markieren();
    blattZeigen();
  }));
  koerper.appendChild(fuss);

  blatt.appendChild(koerper);
  wurzel?.appendChild(blatt);
}

/** Ein Bedienelement aus einer Feldbeschreibung. */
function bedienelement(f) {
  const { wert, eigen } = lesen(gewaehlt, f.stil);

  if (f.art === 'farbe') {
    const reihe = el('div', 'wad-farbe');
    const waehler = el('input', 'wad-eingabe wad-eingabe--farbe');
    waehler.type = 'color';
    waehler.value = alsHexfarbe(wert) || '#000000';
    waehler.addEventListener('input', () => {
      setzen(gewaehlt, f.stil, waehler.value);
      markieren();
    });
    reihe.appendChild(waehler);
    reihe.appendChild(knopf('keine', 'wad-knopf wad-knopf--klein', () => {
      setzen(gewaehlt, f.stil, '');
      markieren();
      blattZeigen();
    }, 'Diese Farbe wieder herausnehmen'));
    return feld(f.name + (eigen ? ' •' : ''), reihe);
  }

  if (f.art === 'bild') {
    const reihe = el('div', 'wad-farbe');
    const jetzt = alsBildpfad(wert);
    reihe.appendChild(knopf(jetzt === '' ? 'Bild wählen' : 'Bild tauschen', 'wad-knopf', () => {
      bildFuerStil = gewaehlt;
      bildZiel = null;
      bildfeld?.click();
    }));
    if (jetzt !== '') {
      reihe.appendChild(knopf('keins', 'wad-knopf wad-knopf--klein', () => {
        setzen(gewaehlt, f.stil, '');
        markieren();
        blattZeigen();
      }));
    }
    return feld(f.name, reihe);
  }

  if (f.art === 'wahl') {
    const s = el('select', 'wad-eingabe');
    f.werte.forEach(([w, beschriftung]) => {
      const o = el('option', null, [beschriftung]);
      o.value = w;
      o.selected = eigen && String(wert) === w;
      s.appendChild(o);
    });
    s.addEventListener('change', () => {
      setzen(gewaehlt, f.stil, s.value);
      markieren();
    });
    return feld(f.name, s);
  }

  // mass und zahl
  const e = el('input', 'wad-eingabe');
  e.type = 'number';
  if (f.min !== undefined) e.min = String(f.min);
  if (f.max !== undefined) e.max = String(f.max);
  if (f.schritt !== undefined) e.step = String(f.schritt);
  e.value = eigen ? alsZahl(wert) : '';
  e.placeholder = alsZahl(wert) || 'wie bisher';
  e.addEventListener('input', () => {
    setzen(gewaehlt, f.stil, e.value === '' ? '' : e.value + (f.einheit ?? ''));
    markieren();
  });
  return feld(f.name + (f.einheit ? ` (${f.einheit})` : ''), e);
}

// ------------------------------------------------------------------ Bausteinleiste

function leisteBauen() {
  const leiste = el('aside', 'wad-vorrat');

  const kopf = el('div', 'wad-vorrat__kopf');
  kopf.appendChild(el('h2', 'wad-vorrat__titel', ['Bausteine']));
  kopf.appendChild(knopf('‹', 'wad-vorrat__falten', () => leisteFalten(), 'Leiste ein- und ausblenden'));
  leiste.appendChild(kopf);

  const liste = el('div', 'wad-vorrat__liste');

  BAUSTEINE.forEach((b) => {
    const kachel = el('button', 'wad-vorrat__knopf');
    kachel.type = 'button';
    kachel.appendChild(el('span', 'wad-vorrat__zeichen', [b.zeichen]));
    kachel.appendChild(el('span', null, [b.name]));
    kachel.title = `${b.name} – ziehen oder anklicken`;

    // Zwei Wege zum selben Ziel: Ziehen für die, die zielen mögen,
    // Klicken für alle anderen. Auf dem Telefon ist Klicken der
    // einzige, der verlässlich funktioniert.
    //
    // `gezogen` trennt die beiden. Ohne das Flag kommt nach jedem Zug
    // zusätzlich ein Klick - und der Baustein landet zweimal in der
    // Seite: einmal dort, wo er hingezogen wurde, und einmal am Ende.
    kachel.addEventListener('click', () => {
      if (kachel.dataset.gezogen === 'ja') { delete kachel.dataset.gezogen; return; }
      einsetzenAmEnde(b.art);
    });

    kachel.addEventListener('pointerdown', (e) => bausteinZiehen(b.art, e, kachel));

    liste.appendChild(kachel);
  });

  leiste.appendChild(liste);
  wurzel?.insertBefore(leiste, wurzel.querySelector('.wa-direkt__buehne'));
}

function leisteFalten() {
  leisteOffen = !leisteOffen;
  wurzel?.classList.toggle('wa-direkt--zu', !leisteOffen);
  const k = document.querySelector('.wad-vorrat__falten');
  if (k) k.textContent = leisteOffen ? '‹' : '›';
}

/** Einen Baustein ans Ende der Seite setzen. */
function einsetzenAmEnde(art) {
  const dok = rahmen?.contentDocument;
  if (!dok?.body) return;

  const neu = bauen(dok, art);
  if (!neu) return;

  dok.body.appendChild(neu);
  neu.scrollIntoView({ block: 'center', behavior: 'smooth' });
  markieren();
  waehlen(neu);

  if (willBild(art)) { bildZiel = neu; bildFuerStil = null; bildfeld?.click(); }
}

/**
 * Einen Baustein aus der Leiste in den Rahmen ziehen.
 *
 * Die Leiste liegt ausserhalb des Rahmens, der Zeiger wandert also
 * über eine Dokumentgrenze. Weil beide dieselbe Herkunft haben, lässt
 * sich hineingreifen: Die Fensterkoordinaten werden um die Lage des
 * Rahmens verschoben, dann weiss das Dokument darin, worüber der
 * Zeiger steht.
 */
function bausteinZiehen(art, start, kachel) {
  if (start.button !== 0) return;

  const dok = rahmen?.contentDocument;
  if (!dok?.body) return;

  start.preventDefault();

  // Den Zeiger festhalten.
  //
  // Ohne das endet der Zug an der Kante des Rahmens: Sobald der Zeiger
  // darüber steht, gehen seine Ereignisse an das Dokument *darin*, und
  // hier draussen kommt nichts mehr an. Der Schatten bliebe stehen, das
  // Ziel würde nie erkannt - der Zug sähe kaputt aus, ohne dass ein
  // Fehler auftaucht.
  try {
    start.target.setPointerCapture?.(start.pointerId);
  } catch {
    /* Verweigert der Browser das, bleibt der Zug auf die Leiste beschränkt. */
  }

  let laeuft = false;
  let ziel = null;

  const geist = el('div', 'wad-geist');
  geist.textContent = BAUSTEINE.find((b) => b.art === art)?.name ?? 'Baustein';

  const bewegen = (e) => {
    if (!laeuft && Math.hypot(e.clientX - start.clientX, e.clientY - start.clientY) < 5) return;

    if (!laeuft) {
      laeuft = true;
      document.body.appendChild(geist);
    }

    geist.style.transform = `translate(${e.clientX + 14}px, ${e.clientY + 14}px)`;

    const r = rahmen.getBoundingClientRect();
    const drin = e.clientX >= r.left && e.clientX <= r.right
      && e.clientY >= r.top && e.clientY <= r.bottom;

    ziel?.removeAttribute(`${MARKE}-ziel`);
    ziel = drin ? blockZu(dok.elementFromPoint(e.clientX - r.left, e.clientY - r.top)) : null;
    ziel?.setAttribute(`${MARKE}-ziel`, '');
  };

  const ende = (e) => {
    document.removeEventListener('pointermove', bewegen);
    document.removeEventListener('pointerup', ende);
    try { start.target.releasePointerCapture?.(start.pointerId); } catch { /* egal */ }
    geist.remove();
    ziel?.removeAttribute(`${MARKE}-ziel`);

    if (!laeuft) return;

    // Dem Klick, der gleich folgt, sagen, dass er schon erledigt ist.
    if (kachel) kachel.dataset.gezogen = 'ja';

    const neu = bauen(dok, art);
    if (!neu) return;

    if (ziel && ziel !== dok.body) {
      // Obere Hälfte davor, untere dahinter - so, wie man es hinlegt.
      const k = ziel.getBoundingClientRect();
      const r = rahmen.getBoundingClientRect();
      const oben = (e.clientY - r.top) < k.top + k.height / 2;
      ziel[oben ? 'before' : 'after'](neu);
    } else {
      dok.body.appendChild(neu);
    }

    markieren();
    waehlen(neu);

    if (willBild(art)) { bildZiel = neu; bildFuerStil = null; bildfeld?.click(); }
  };

  document.addEventListener('pointermove', bewegen);
  document.addEventListener('pointerup', ende);
}

/** Einen Zug, der am Griff der Werkzeugleiste beginnt, in den Rahmen reichen. */
function zugAusLeiste(block, start) {
  const dok = rahmen?.contentDocument;
  if (!dok || !block) return;

  // Denselben Grund wie oben: über dem Rahmen hört das Hauptdokument
  // sonst auf, den Zeiger zu sehen.
  try {
    start.target.setPointerCapture?.(start.pointerId);
  } catch {
    /* Dann eben ohne - der Zug endet an der Kante. */
  }

  const ziele = kandidaten().filter((e) => e !== block && !block.contains(e));
  let ziel = null;

  const geist = el('div', 'wad-geist');
  geist.textContent = block.tagName.toLowerCase();
  document.body.appendChild(geist);
  block.setAttribute(`${MARKE}-gezogen`, '');

  const bewegen = (e) => {
    geist.style.transform = `translate(${e.clientX + 14}px, ${e.clientY + 14}px)`;

    const r = rahmen.getBoundingClientRect();
    const x = e.clientX - r.left;
    const y = e.clientY - r.top;

    let treffer = null;
    let flaeche = Infinity;

    for (const el of ziele) {
      const k = el.getBoundingClientRect();
      if (x < k.left || x > k.right || y < k.top || y > k.bottom) continue;
      const gross = k.width * k.height;
      if (gross < flaeche) { flaeche = gross; treffer = el; }
    }

    if (treffer === ziel) return;
    ziel?.removeAttribute(`${MARKE}-ziel`);
    ziel = treffer;
    ziel?.setAttribute(`${MARKE}-ziel`, '');
  };

  const ende = () => {
    document.removeEventListener('pointermove', bewegen);
    document.removeEventListener('pointerup', ende);
    try { start.target.releasePointerCapture?.(start.pointerId); } catch { /* egal */ }
    geist.remove();
    block.removeAttribute(`${MARKE}-gezogen`);
    ziel?.removeAttribute(`${MARKE}-ziel`);

    if (ziel && ziel !== block) {
      tauschen(block, ziel);
      markieren();
      werkzeugeStellen();
    }
  };

  document.addEventListener('pointermove', bewegen);
  document.addEventListener('pointerup', ende);
}

// ------------------------------------------------------------------ Der Rahmen

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
    [${MARKE}-wahl] { outline: 2px solid #2fe0dc !important; outline-offset: -2px; }
    [${MARKE}-ziel] { outline: 3px dashed #8b85ff !important; outline-offset: -3px;
                      background: rgba(139,133,255,.12) !important; }
    [${MARKE}-gezogen] { opacity: .4; }
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
      bildFuerStil = null;
      bildfeld?.click();
      waehlen(ziel);
      return;
    }

    // Auswählen tut jeder Klick - erst damit kommt man an Hintergrund
    // und Abstände. Ist es reiner Text, wird er zusätzlich sofort
    // beschreibbar; sonst wäre der zweite Klick nötig, und den sucht
    // niemand.
    waehlen(blockZu(ziel));

    if (nurText(ziel)) {
      ziel.setAttribute('contenteditable', 'true');
      ziel.focus();
    }
  });

  dok.addEventListener('input', markieren, true);

  dok.addEventListener('blur', (e) => {
    e.target?.removeAttribute?.('contenteditable');
  }, true);

  dok.addEventListener('scroll', werkzeugeStellen, { passive: true });
  dok.defaultView?.addEventListener('resize', werkzeugeStellen);

  // Ziehen im Rahmen selbst: am gewählten Block anfassen und auf einen
  // anderen fallen lassen.
  greifbar({
    dokument: dok,
    griffe: () => `[${MARKE}-wahl]`,
    block: (el) => el,
    kandidaten,
    getauscht: (a, b) => { tauschen(a, b); markieren(); werkzeugeStellen(); },
  });
}

/** Die Seite so serialisieren, wie sie ohne Bearbeitung aussähe. */
function alsHtml(dok) {
  // Das ganze Dokument, nicht nur <html>.
  //
  // Das ist keine Feinheit: Bei einer PHP-Seite steht der erste Block
  // fast immer VOR dem Doctype (`<?php include ... ?>`), und sein
  // Platzhalter ist damit ein Geschwister von <html>, kein Kind.
  // documentElement.cloneNode() verlor ihn stillschweigend - und beim
  // Speichern fehlte dann Code des Kunden.
  let aus = '';

  for (const knoten of dok.childNodes) {
    if (knoten.nodeType === 10) {
      // Doctype
      aus += `<!DOCTYPE ${knoten.name}>\n`;
    } else if (knoten.nodeType === 8) {
      aus += `<!--${knoten.nodeValue}-->`;
    } else if (knoten.nodeType === 1) {
      aus += saeubern(knoten).outerHTML;
    } else if (knoten.nodeType === 3) {
      aus += knoten.nodeValue;
    }
  }

  return aus;
}

/**
 * Eine Kopie ohne jede Spur des Werkzeugs.
 *
 * Auf einer Kopie, nicht am Original: Wer die Marken aus dem laufenden
 * Dokument entfernt, nimmt dem Bearbeiter mitten in der Arbeit sein
 * Werkzeug weg.
 */
function saeubern(el) {
  const kopie = el.cloneNode(true);

  kopie.querySelectorAll(`[${MARKE}]`).forEach((e) => e.remove());
  kopie.querySelectorAll('[contenteditable]').forEach((e) => e.removeAttribute('contenteditable'));

  // Jedes Attribut, das mit data-wa- beginnt - und nicht eine Liste
  // einzelner Namen. Die vergisst man beim nächsten Werkzeug, und dann
  // steht sie in der Datei des Kunden.
  [kopie, ...kopie.querySelectorAll('*')].forEach((e) => {
    [...e.attributes]
      .filter((a) => a.name.startsWith('data-wa-'))
      .forEach((a) => e.removeAttribute(a.name));
  });

  // contenteditable hinterlaesst in manchen Browsern ein leeres
  // style-Attribut. Es tut nichts - aber es steht danach in der Datei
  // des Kunden, und was nicht hineingehoert, bleibt auch nicht drin.
  kopie.querySelectorAll('[style=""]').forEach((e) => e.removeAttribute('style'));
  if (kopie.getAttribute('style') === '') kopie.removeAttribute('style');

  return kopie;
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
  const ziel = bildZiel;
  const fuerStil = bildFuerStil;

  if (!datei || (!ziel && !fuerStil)) return;

  melden('Bild wird eingesetzt …');

  const formular = new FormData();
  formular.append('bild', datei);
  formular.append('_token', daten.token ?? '');

  try {
    const antwort = await anfrage(`${daten.base}/direkt/${daten.id}/bild`, formular);

    // Der Pfad steht ab der Wurzel des Stands - relativ zur gerade
    // gezeigten Seite muss er ebenso viele Ebenen zurueckgehen.
    const tiefe = seite.split('/').length - 1;
    const pfad = '../'.repeat(tiefe) + antwort.pfad;

    if (fuerStil) {
      setzen(fuerStil, 'backgroundImage', `url("${pfad}")`);
      if (lesen(fuerStil, 'backgroundSize').wert === '') {
        setzen(fuerStil, 'backgroundSize', 'cover');
        setzen(fuerStil, 'backgroundPosition', 'center');
      }
      blattZeigen();
    } else {
      ziel.setAttribute('src', pfad);
      ziel.removeAttribute('srcset');
    }

    markieren();
    melden('Bild eingesetzt', 'gut');
  } catch (fehler) {
    melden(fehler.message, 'schlecht');
  }

  bildZiel = null;
  bildFuerStil = null;
}

// ------------------------------------------------------------------ Anschluss

if (rahmen) {
  leisteBauen();

  rahmen.addEventListener('load', () => {
    abwaehlen();
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
    abwaehlen();
    if (speichernKnopf) speichernKnopf.disabled = true;
    rahmen.src = `${daten.base}/direkt/${daten.id}/datei/${seite}`;
  });

  // Wer den Rahmen verlässt, ohne zu speichern, verliert die Arbeit.
  window.addEventListener('beforeunload', (e) => {
    if (!geaendert) return;
    e.preventDefault();
    e.returnValue = '';
  });

  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      if (geaendert) speichern();
    }
    if (e.key === 'Escape') abwaehlen();
  });

  window.addEventListener('resize', werkzeugeStellen);
}
