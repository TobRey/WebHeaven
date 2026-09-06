/**
 * Eine fremde Website bearbeiten.
 *
 * ## Warum dieser Editor neu geschrieben wurde
 *
 * Der alte las beim Speichern das Dokument aus dem Rahmen aus und
 * schickte es als Ganzes zurück. Das hat einen Fehler, den man erst
 * bemerkt, wenn er auftritt: Eine Kundenwebsite bringt ihr eigenes
 * JavaScript mit. Ein Cookie-Hinweis, eine Weiterleitung, ein
 * `location.reload()` - und der Rahmen lädt neu. Die Arbeit im DOM ist
 * dann weg, und beim Speichern wurde das frisch geladene **Original**
 * zurückgeschrieben. Gemeldet wurde "gespeichert".
 *
 * Deshalb gilt hier:
 *
 *   1. **Notiert wird beim Ändern, nicht beim Speichern.** Das Journal
 *      liegt neben dem Rahmen und überlebt, was darin passiert.
 *   2. **Geschickt wird eine Liste von Änderungen**, keine Seite. Der
 *      Server fasst nur die genannten Stellen an; der Rest der Datei
 *      bleibt Byte für Byte stehen.
 *   3. **Jedes Element trägt eine Nummer, die vom Server kommt.** Der
 *      Browser zählt nicht selbst - er zählt anders als eine Datei
 *      (eingefügte `<tbody>`, geschlossene Tags), und dann würde an der
 *      falschen Stelle geschrieben.
 *   4. **Nach dem Schreiben liest der Server zurück.** "Gespeichert"
 *      heisst: Es steht nachweislich in der Datei.
 */

import './direkt.css';
import { greifbar, tauschen, schieben } from './greifen.js';
import { BAUSTEINE, bauen, willBild } from './bausteine.js';
import { journal } from './journal.js';
import {
  REITER, FELDER, lesen, setzen, zuruecksetzen,
  hintergrund, alsHexfarbe, alsZahl, alsBildpfad, alsSchleier,
} from './einstellungen.js';

const daten = JSON.parse(document.getElementById('wa-direkt-daten')?.textContent || '{}');

const wurzel = document.querySelector('[data-direkt]');
const rahmen = wurzel?.querySelector('[data-direkt-rahmen]');
const stand = wurzel?.querySelector('[data-direkt-stand]');
const speichernKnopf = wurzel?.querySelector('[data-direkt-speichern]');
const seitenwahl = wurzel?.querySelector('[data-direkt-seite]');
const bildfeld = wurzel?.querySelector('[data-direkt-bildfeld]');

const buch = journal();

let seite = daten.seite ?? '';
let finger = daten.finger ?? '';
let bildZiel = null;
let bildFuerStil = null;
let gewaehlt = null;
let reiter = 'stil';
let naechsteNeue = -1;   // Nummern für Elemente, die es in der Datei noch nicht gibt

// Nach dem Speichern lädt der Rahmen neu, und der Rahmen meldet danach
// "bereit". Damit stand die Bestätigung keine Sekunde da - und die
// fehlende Bestätigung war der Anlass für diesen ganzen Umbau. Der
// Merker hält sie über genau dieses eine Neuladen hinweg.
let bestaetigung = '';

/** Was das Skript in die Seite schreibt, damit es sich wieder ausräumen lässt. */
const MARKE = 'data-wa-direkt';

// ------------------------------------------------------------------ Bausteine

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

/**
 * Etwas sagen.
 *
 * Zwei Wege, und der zweite ist neu: Die kleine Pille oben zeigt den
 * Zustand, ein Streifen über der Bühne zeigt Fehler. Vorher stand
 * beides in derselben 0.8-rem-Pille - eine Absage von hundertfünfzig
 * Zeichen ging dort unter, und der Bearbeiter berichtete "es passiert
 * nichts", obwohl der Server genau gesagt hatte, was los war.
 */
function melden(text, art = 'ruhig') {
  if (stand) {
    stand.textContent = text;
    stand.dataset.art = art;
  }

  document.querySelector('.wad-lautsprecher')?.remove();

  if (art !== 'schlecht' && art !== 'warnung') return;

  const streifen = el('div', 'wad-lautsprecher' + (art === 'warnung' ? ' wad-lautsprecher--warnung' : ''));
  streifen.setAttribute('role', 'alert');
  streifen.appendChild(el('span', null, [text]));
  streifen.appendChild(knopf('×', 'wad-lautsprecher__zu', () => streifen.remove(), 'Schliessen'));
  wurzel?.appendChild(streifen);
}

function offen() {
  const n = buch.anzahl();

  if (speichernKnopf) speichernKnopf.disabled = n === 0;

  if (n > 0) {
    melden(n === 1 ? '1 Änderung offen' : `${n} Änderungen offen`, 'offen');
  }
}

/** Die letzte Nummer, die in diesem Element vorkommt. */
function letzteNummerIn(element) {
  const alle = element?.querySelectorAll?.('[data-wa-id]');

  for (let i = (alle?.length ?? 0) - 1; i >= 0; i--) {
    const n = nummerVon(alle[i]);
    if (n !== null && n >= 0) return n;
  }

  return null;
}

/** Die Nummer, unter der der Server dieses Element kennt. */
function nummerVon(element) {
  const roh = element?.getAttribute?.('data-wa-id');

  return roh === null || roh === undefined || roh === '' ? null : Number(roh);
}

// ------------------------------------------------------------------ Auswahl

/**
 * Taugt dieses Element als Textfeld?
 *
 * Nur Blattelemente, und keines mit weggeblendetem PHP darin: Dessen
 * Platzhalter ist ein Kommentarknoten, den das Bearbeiten mitlöschen
 * würde - und was danach in der Datei fehlte, wäre Code des Kunden.
 */
function nurText(element) {
  if (!element || element.nodeType !== 1) return false;
  if (element.children.length > 0) return false;
  if (hatPhp(element)) return false;
  if (nummerVon(element) === null) return false;
  const text = (element.textContent ?? '').trim();
  if (text === '') return false;
  return !['SCRIPT', 'STYLE', 'TITLE', 'NOSCRIPT'].includes(element.tagName);
}

function hatPhp(element) {
  for (const knoten of element.childNodes) {
    if (knoten.nodeType === 8 && (knoten.nodeValue ?? '').startsWith('wa-php-')) return true;
  }
  return element.innerHTML?.includes('__WAPHP') === true;
}

/** Der Block, den ein Klick meint - der nächste mit einer Nummer. */
function blockZu(element) {
  let n = element;

  while (n && n.nodeType === 1 && n !== n.ownerDocument.body) {
    if (nummerVon(n) !== null) return n;
    n = n.parentElement;
  }

  return null;
}

function kandidaten() {
  const dok = rahmen?.contentDocument;
  if (!dok?.body) return [];

  return [...dok.body.querySelectorAll('[data-wa-id]')].filter((e) => {
    const k = e.getBoundingClientRect();
    return k.width > 12 && k.height > 12;
  });
}

function waehlen(element) {
  abwaehlen();
  if (!element) return;

  gewaehlt = element;
  element.setAttribute(`${MARKE}-wahl`, '');
  werkzeugeZeigen();
  blattZeigen();
}

function abwaehlen() {
  rahmen?.contentDocument?.querySelectorAll(`[${MARKE}-wahl]`)
    .forEach((e) => e.removeAttribute(`${MARKE}-wahl`));
  gewaehlt = null;
  document.querySelector('.wad-werkzeuge')?.remove();
  document.querySelector('.wad-blatt')?.remove();
}

// ------------------------------------------------------------------ Werkzeuge

function werkzeugeZeigen() {
  document.querySelector('.wad-werkzeuge')?.remove();
  if (!gewaehlt) return;

  const leiste = el('div', 'wad-werkzeuge');

  const griff = el('span', 'wad-werkzeuge__griff', ['⠿']);
  griff.title = 'Ziehen und auf einen anderen Block fallen lassen – die beiden tauschen';
  leiste.appendChild(griff);

  leiste.appendChild(el('span', 'wad-werkzeuge__name', [gewaehlt.tagName.toLowerCase()]));

  leiste.appendChild(knopf('↑', 'wad-werkzeuge__knopf', () => nachbarTausch(-1), 'Nach oben'));
  leiste.appendChild(knopf('↓', 'wad-werkzeuge__knopf', () => nachbarTausch(1), 'Nach unten'));

  leiste.appendChild(knopf('⤒', 'wad-werkzeuge__knopf', () => {
    const eltern = blockZu(gewaehlt.parentElement);
    if (eltern) waehlen(eltern);
  }, 'Das umgebende Element wählen'));

  leiste.appendChild(knopf('🗑', 'wad-werkzeuge__knopf wad-werkzeuge__knopf--gefahr', () => {
    if (gewaehlt.querySelector('[data-wa-id]') !== null
      && !window.confirm('Darin liegen weitere Blöcke. Alles zusammen löschen?')) return;
    if (!window.confirm('Diesen Block löschen?')) return;

    const nummer = nummerVon(gewaehlt);
    buch.vergessen(nummer);
    buch.merken(nummer, 'entfernen', {});
    gewaehlt.remove();
    abwaehlen();
    offen();
  }, 'Löschen'));

  document.body.appendChild(leiste);
  werkzeugeStellen();

  griff.addEventListener('pointerdown', (e) => {
    e.preventDefault();
    zugAusLeiste(gewaehlt, e);
  });
}

function nachbarTausch(richtung) {
  const nachbar = richtung < 0 ? gewaehlt.previousElementSibling : gewaehlt.nextElementSibling;
  const a = nummerVon(gewaehlt);
  const b = nummerVon(nachbar);

  if (a === null || b === null) {
    melden('Daneben liegt nichts, was sich tauschen lässt.', 'warnung');
    return;
  }

  if (!schieben(gewaehlt, richtung)) return;

  buch.merken(a, 'tausch', { mit: b });
  offen();
  werkzeugeStellen();
}

function werkzeugeStellen() {
  const leiste = document.querySelector('.wad-werkzeuge');
  if (!leiste || !gewaehlt || !rahmen) return;

  const r = rahmen.getBoundingClientRect();
  const k = gewaehlt.getBoundingClientRect();
  const oben = k.top > 40 ? r.top + k.top - 38 : r.top + k.bottom + 6;

  leiste.style.transform = `translate(${Math.max(r.left + 4, r.left + k.left)}px, ${oben}px)`;
  leiste.hidden = k.bottom < 0 || k.top > r.height;
}

// ------------------------------------------------------------------ Das Blatt

function blattZeigen() {
  document.querySelector('.wad-blatt')?.remove();
  if (!gewaehlt) return;

  const blatt = el('div', 'wad-blatt');

  const kopf = el('div', 'wad-blatt__kopf');
  kopf.appendChild(el('strong', null, [gewaehlt.tagName.toLowerCase()]));
  kopf.appendChild(knopf('×', 'wad-blatt__zu', () => abwaehlen(), 'Schliessen'));
  blatt.appendChild(kopf);

  const leiste = el('div', 'wad-reiter');

  REITER.forEach((r) => {
    const k = knopf(r.name, 'wad-reiter__knopf' + (r.schluessel === reiter ? ' ist-an' : ''), () => {
      reiter = r.schluessel;
      blattZeigen();
    });
    k.setAttribute('aria-pressed', String(r.schluessel === reiter));
    leiste.appendChild(k);
  });

  blatt.appendChild(leiste);

  const koerper = el('div', 'wad-blatt__koerper');
  let gruppe = null;

  (FELDER[reiter] ?? []).forEach((f) => {
    if (f.gruppe) {
      gruppe = el('section', 'wad-gruppe');
      gruppe.appendChild(el('h3', 'wad-gruppe__titel', [f.gruppe]));
      koerper.appendChild(gruppe);
      return;
    }

    if (f.nur && !f.nur.includes(gewaehlt.tagName.toLowerCase())) return;

    const stueck = bedienelement(f);
    if (stueck) (gruppe ?? koerper).appendChild(stueck);
  });

  if (koerper.childElementCount === 0) {
    koerper.appendChild(el('p', 'wad-leer', ['Für dieses Element gibt es hier nichts einzustellen.']));
  }

  blatt.appendChild(koerper);
  wurzel?.appendChild(blatt);
}

/** Nach jeder Stiländerung: im Element setzen und ins Journal. */
function stilSetzen(stil, wert) {
  setzen(gewaehlt, stil, wert);
  buch.merken(nummerVon(gewaehlt), 'stil', { wert: gewaehlt.getAttribute('style') ?? '' });
  offen();
}

function bedienelement(f) {
  if (f.art === 'hinweis') {
    return el('p', 'wad-hinweis', [f.name]);
  }

  if (f.art === 'zuruecksetzen') {
    return knopf(f.name, 'wad-knopf', () => {
      zuruecksetzen(gewaehlt);
      buch.merken(nummerVon(gewaehlt), 'stil', { wert: gewaehlt.getAttribute('style') ?? '' });
      offen();
      blattZeigen();
    });
  }

  if (f.art === 'verweis') {
    const e = el('input', 'wad-eingabe');
    e.type = 'text';
    e.value = gewaehlt.getAttribute('href') ?? '';
    e.placeholder = 'https://…';
    e.addEventListener('change', () => {
      gewaehlt.setAttribute('href', e.value);
      buch.merken(nummerVon(gewaehlt), 'attribut', { name: 'href', wert: e.value });
      offen();
    });
    return feld(f.name, e);
  }

  if (f.art === 'bildtausch') {
    return feld(f.name, knopf('Bild tauschen', 'wad-knopf', () => {
      bildZiel = gewaehlt;
      bildFuerStil = null;
      bildfeld?.click();
    }));
  }

  if (f.art === 'farbe') {
    const { wert, eigen } = lesen(gewaehlt, f.stil);
    const reihe = el('div', 'wad-farbe');
    const waehler = el('input', 'wad-eingabe wad-eingabe--farbe');
    waehler.type = 'color';
    waehler.value = alsHexfarbe(wert) || '#ffffff';
    waehler.addEventListener('input', () => stilSetzen(f.stil, waehler.value));
    reihe.appendChild(waehler);
    reihe.appendChild(knopf('keine', 'wad-knopf wad-knopf--klein', () => {
      stilSetzen(f.stil, '');
      blattZeigen();
    }, 'Diese Farbe wieder herausnehmen'));
    return feld(f.name + (eigen ? ' •' : ''), reihe);
  }

  if (f.art === 'bild') {
    const jetzt = alsBildpfad(lesen(gewaehlt, 'backgroundImage').wert);
    const reihe = el('div', 'wad-farbe');
    reihe.appendChild(knopf(jetzt === '' ? 'Bild wählen' : 'Bild tauschen', 'wad-knopf', () => {
      bildFuerStil = gewaehlt;
      bildZiel = null;
      bildfeld?.click();
    }));
    if (jetzt !== '') {
      reihe.appendChild(knopf('keins', 'wad-knopf wad-knopf--klein', () => {
        const s = alsSchleier(lesen(gewaehlt, 'backgroundImage').wert);
        stilSetzen('backgroundImage', hintergrund('', s.farbe, s.staerke));
        blattZeigen();
      }));
    }
    return feld(f.name, reihe);
  }

  if (f.art === 'overlay') {
    const roh = lesen(gewaehlt, 'backgroundImage').wert;
    const bild = alsBildpfad(roh);
    const s = alsSchleier(roh);

    const reihe = el('div', 'wad-farbe');
    const waehler = el('input', 'wad-eingabe wad-eingabe--farbe');
    waehler.type = 'color';
    waehler.value = s.farbe || '#000000';

    const regler = el('input', 'wad-regler');
    regler.type = 'range';
    regler.min = '0';
    regler.max = '100';
    regler.step = '5';
    regler.value = String(s.staerke);

    const zahl = el('span', 'wad-regler__wert', [`${s.staerke}%`]);

    const anwenden = () => {
      zahl.textContent = `${regler.value}%`;
      stilSetzen('backgroundImage', hintergrund(bild, waehler.value, Number(regler.value)));
    };

    waehler.addEventListener('input', anwenden);
    regler.addEventListener('input', anwenden);

    reihe.appendChild(waehler);
    reihe.appendChild(regler);
    reihe.appendChild(zahl);

    return feld(f.name, reihe);
  }

  if (f.art === 'knopfreihe') {
    const { wert, eigen } = lesen(gewaehlt, f.stil);
    const reihe = el('div', 'wad-knopfreihe');

    f.werte.forEach(([w, beschriftung]) => {
      const aktiv = eigen && String(wert).startsWith(w) && w !== '';
      const k = knopf(beschriftung, 'wad-knopfreihe__knopf' + (aktiv ? ' ist-an' : ''), () => {
        stilSetzen(f.stil, aktiv ? '' : w);
        blattZeigen();
      });
      k.setAttribute('aria-pressed', String(aktiv));
      reihe.appendChild(k);
    });

    return feld(f.name, reihe);
  }

  if (f.art === 'regler') {
    const { wert, eigen } = lesen(gewaehlt, f.stil);
    const reihe = el('div', 'wad-farbe');
    const regler = el('input', 'wad-regler');
    regler.type = 'range';
    regler.min = String(f.min);
    regler.max = String(f.max);
    regler.step = String(f.schritt);
    regler.value = eigen ? (alsZahl(wert) || '0') : (alsZahl(wert) || '0');

    const zahl = el('span', 'wad-regler__wert', [`${regler.value}${f.einheit}`]);

    regler.addEventListener('input', () => {
      zahl.textContent = `${regler.value}${f.einheit}`;
      stilSetzen(f.stil, regler.value + f.einheit);
    });

    reihe.appendChild(regler);
    reihe.appendChild(zahl);
    return feld(f.name, reihe);
  }

  // Eine Auswahl aus wenigen festen Werten - Ausschnitt und Position
  // des Hintergrundbildes.
  //
  // Ohne diesen Zweig fiel `wahl` bis hierher durch und wurde als
  // Zahlenfeld gezeichnet: "Position" stand als `0` da, "Ausschnitt"
  // als leeres Feld. Beides sah aus wie eine Einstellung und war
  // keine.
  if (f.art === 'wahl') {
    const { wert, eigen } = lesen(gewaehlt, f.stil);
    const auswahl = el('select', 'wa-select wad-eingabe');

    (f.werte ?? []).forEach(([w, beschriftung]) => {
      const o = el('option', null, [beschriftung]);
      o.value = w;
      auswahl.appendChild(o);
    });

    // Nur ein selbst gesetzter Wert wird angezeigt. Der berechnete ist
    // immer belegt - "0% 0%" etwa -, und stünde er hier, sähe jedes
    // Element aus, als wäre daran schon etwas eingestellt worden.
    auswahl.value = eigen && [...auswahl.options].some((o) => o.value === wert) ? wert : '';

    auswahl.addEventListener('change', () => stilSetzen(f.stil, auswahl.value));

    return feld(f.name, auswahl);
  }

  // Eine Zahl mit Einheit. Ausdrücklich abgefragt und nicht als
  // Auffangbecken: Solange jede unbekannte Art hier landete, wurde
  // aus einer fehlenden Verzweigung ein Zahlenfeld, das aussah wie
  // eine Einstellung - so geschehen bei "Ausschnitt" und "Position".
  if (f.art !== 'mass') {
    return null;
  }

  const { wert, eigen } = lesen(gewaehlt, f.stil);
  const e = el('input', 'wad-eingabe');
  e.type = 'number';
  if (f.min !== undefined) e.min = String(f.min);
  if (f.max !== undefined) e.max = String(f.max);
  if (f.schritt !== undefined) e.step = String(f.schritt);
  e.value = eigen ? alsZahl(wert) : '';
  e.placeholder = alsZahl(wert) || 'wie bisher';
  e.addEventListener('input', () => {
    stilSetzen(f.stil, e.value === '' ? '' : e.value + (f.einheit ?? ''));
  });
  return feld(f.name + (f.einheit ? ` (${f.einheit})` : ''), e);
}

// ------------------------------------------------------------------ Bausteinleiste

function leisteBauen() {
  const leiste = el('aside', 'wad-vorrat');

  const kopf = el('div', 'wad-vorrat__kopf');
  kopf.appendChild(el('h2', 'wad-vorrat__titel', ['Bausteine']));
  kopf.appendChild(knopf('‹', 'wad-vorrat__falten', () => {
    const zu = wurzel.classList.toggle('wa-direkt--zu');
    document.querySelector('.wad-vorrat__falten').textContent = zu ? '›' : '‹';
  }, 'Leiste ein- und ausblenden'));
  leiste.appendChild(kopf);

  const liste = el('div', 'wad-vorrat__liste');

  BAUSTEINE.forEach((b) => {
    const kachel = el('button', 'wad-vorrat__knopf');
    kachel.type = 'button';
    kachel.appendChild(el('span', 'wad-vorrat__zeichen', [b.zeichen]));
    kachel.appendChild(el('span', null, [b.name]));
    kachel.title = `${b.name} – ziehen oder anklicken`;

    kachel.addEventListener('click', () => {
      if (kachel.dataset.gezogen === 'ja') { delete kachel.dataset.gezogen; return; }
      einsetzen(b.art, null, false);
    });

    kachel.addEventListener('pointerdown', (e) => bausteinZiehen(b.art, e, kachel));

    liste.appendChild(kachel);
  });

  leiste.appendChild(liste);
  wurzel?.insertBefore(leiste, wurzel.querySelector('.wa-direkt__buehne'));
}

/**
 * Einen Baustein einsetzen.
 *
 * Er bekommt eine negative Nummer. Die Datei kennt ihn noch nicht -
 * positive Nummern gehören dem Server, negative sind neu und werden
 * beim Speichern zu echtem HTML. So bleiben beide Zählungen getrennt
 * und können nicht kollidieren.
 */
function einsetzen(art, ziel, davor) {
  const dok = rahmen?.contentDocument;
  if (!dok?.body) return;

  const neu = bauen(dok, art);
  if (!neu) return;

  const nummer = naechsteNeue--;
  neu.setAttribute('data-wa-id', String(nummer));

  const anker = ziel && nummerVon(ziel) !== null ? ziel : null;

  if (anker) {
    anker[davor ? 'before' : 'after'](neu);
  } else {
    dok.body.appendChild(neu);
  }

  // Der Server bekommt das fertige HTML und die Stelle, an die es soll.
  //
  // `anker` und nicht `id`: Die Nummer des neuen Bausteins ist negativ
  // und steht in der Datei noch nirgends - der Server braucht die
  // Nummer des Elements, NEBEN das er ihn setzen soll.
  const ankerNummer = anker
    ? nummerVon(anker)
    : letzteNummerIn(dok.body);

  if (ankerNummer === null) {
    melden('Hier lässt sich noch nichts einsetzen – speichere zuerst.', 'warnung');
    neu.remove();
    return;
  }

  buch.merken(nummer, 'einfuegen', {
    anker: ankerNummer,
    wert: neu.outerHTML.replace(/\sdata-wa-[a-z-]*="[^"]*"/g, ''),
    davor: anker ? davor : false,
  });

  neu.scrollIntoView({ block: 'center', behavior: 'smooth' });
  offen();
  waehlen(neu);

  if (willBild(art)) { bildZiel = neu; bildFuerStil = null; bildfeld?.click(); }
}

function bausteinZiehen(art, start, kachel) {
  if (start.button !== 0) return;

  const dok = rahmen?.contentDocument;
  if (!dok?.body) return;

  start.preventDefault();

  // Ohne das endet der Zug an der Kante des Rahmens: Sobald der Zeiger
  // darüber steht, gehen seine Ereignisse an das Dokument darin.
  try { start.target.setPointerCapture?.(start.pointerId); } catch { /* dann ohne */ }

  let laeuft = false;
  let ziel = null;
  let obenHalb = false;

  const geist = el('div', 'wad-geist');
  geist.textContent = BAUSTEINE.find((b) => b.art === art)?.name ?? 'Baustein';

  const bewegen = (e) => {
    if (!laeuft && Math.hypot(e.clientX - start.clientX, e.clientY - start.clientY) < 5) return;
    if (!laeuft) { laeuft = true; document.body.appendChild(geist); }

    geist.style.transform = `translate(${e.clientX + 14}px, ${e.clientY + 14}px)`;

    const r = rahmen.getBoundingClientRect();
    const drin = e.clientX >= r.left && e.clientX <= r.right
      && e.clientY >= r.top && e.clientY <= r.bottom;

    ziel?.removeAttribute(`${MARKE}-ziel`);
    ziel = drin ? blockZu(dok.elementFromPoint(e.clientX - r.left, e.clientY - r.top)) : null;
    ziel?.setAttribute(`${MARKE}-ziel`, '');

    if (ziel) {
      const k = ziel.getBoundingClientRect();
      obenHalb = (e.clientY - r.top) < k.top + k.height / 2;
    }
  };

  const ende = () => {
    document.removeEventListener('pointermove', bewegen);
    document.removeEventListener('pointerup', ende);
    try { start.target.releasePointerCapture?.(start.pointerId); } catch { /* egal */ }
    geist.remove();
    ziel?.removeAttribute(`${MARKE}-ziel`);

    if (!laeuft) return;

    if (kachel) kachel.dataset.gezogen = 'ja';
    einsetzen(art, ziel, obenHalb);
  };

  document.addEventListener('pointermove', bewegen);
  document.addEventListener('pointerup', ende);
}

function zugAusLeiste(block, start) {
  const dok = rahmen?.contentDocument;
  if (!dok || !block) return;

  try { start.target.setPointerCapture?.(start.pointerId); } catch { /* dann ohne */ }

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

    for (const kandidat of ziele) {
      const k = kandidat.getBoundingClientRect();
      if (x < k.left || x > k.right || y < k.top || y > k.bottom) continue;
      const gross = k.width * k.height;
      if (gross < flaeche) { flaeche = gross; treffer = kandidat; }
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

    if (!ziel || ziel === block) return;

    const a = nummerVon(block);
    const b = nummerVon(ziel);

    if (a === null || b === null) {
      melden('Einer der beiden Blöcke ist neu – erst speichern, dann tauschen.', 'warnung');
      return;
    }

    tauschen(block, ziel);
    buch.merken(a, 'tausch', { mit: b });
    offen();
    werkzeugeStellen();
  };

  document.addEventListener('pointermove', bewegen);
  document.addEventListener('pointerup', ende);
}

// ------------------------------------------------------------------ Der Rahmen

function herrichten(dok) {
  if (!dok || !dok.body) return;

  // Beim zweiten Mal nicht noch einmal: `load` kann mehrfach kommen,
  // und dann hinge an jedem Klick ein zweiter Satz Listener.
  if (dok.documentElement.hasAttribute(`${MARKE}-bereit`)) return;
  dok.documentElement.setAttribute(`${MARKE}-bereit`, '');

  dok.addEventListener('click', (e) => {
    const verweis = e.target.closest?.('a');
    if (verweis) e.preventDefault();
  }, true);

  dok.addEventListener('submit', (e) => e.preventDefault(), true);

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
      waehlen(ziel);
      bildfeld?.click();
      return;
    }

    waehlen(blockZu(ziel));

    if (nurText(ziel)) {
      ziel.setAttribute('contenteditable', 'true');
      ziel.focus();
    }
  });

  // Hier entsteht das Journal: bei jedem Tastendruck, nicht am Ende.
  dok.addEventListener('input', (e) => {
    const element = e.target?.closest?.('[contenteditable="true"]');
    const nummer = nummerVon(element);

    if (nummer === null) return;

    buch.merken(nummer, 'text', { wert: element.innerHTML });
    offen();
  }, true);

  dok.addEventListener('blur', (e) => {
    e.target?.removeAttribute?.('contenteditable');
  }, true);

  dok.addEventListener('scroll', werkzeugeStellen, { passive: true });
  dok.defaultView?.addEventListener('resize', werkzeugeStellen);

  greifbar({
    dokument: dok,
    griffe: () => `[${MARKE}-wahl]`,
    block: (element) => element,
    kandidaten,
    getauscht: (a, b) => {
      const na = nummerVon(a);
      const nb = nummerVon(b);
      if (na === null || nb === null) return;
      tauschen(a, b);
      buch.merken(na, 'tausch', { mit: nb });
      offen();
      werkzeugeStellen();
    },
  });
}

// ------------------------------------------------------------------ Speichern

async function anfrage(url, koerper) {
  const antwort = await fetch(url, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': daten.token ?? '',
    },
    body: koerper,
  });

  let inhalt = null;

  try {
    inhalt = await antwort.json();
  } catch {
    // Kein JSON heisst: Da hat etwas anderes geantwortet - eine
    // Fehlerseite, ein Schutzfilter, eine Anmeldemaske. Vorher galt
    // das als Erfolg, weil nur auf `ok === false` geprüft wurde und
    // ein leeres Objekt das nicht ist.
    throw new Error(`Der Server hat keine verwertbare Antwort geschickt (${antwort.status}).`);
  }

  if (!antwort.ok || inhalt?.ok !== true) {
    const fehler = new Error(inhalt?.error ?? `Die Anfrage ist fehlgeschlagen (${antwort.status}).`);
    fehler.neuLaden = inhalt?.neuLaden === true;
    throw fehler;
  }

  return inhalt;
}

async function speichern() {
  if (buch.anzahl() === 0) {
    melden('Nichts zu speichern.');
    return;
  }

  melden('speichert …');
  if (speichernKnopf) speichernKnopf.disabled = true;

  const formular = new FormData();
  formular.append('seite', seite);
  formular.append('finger', finger);
  formular.append('aenderungen', JSON.stringify(buch.liste()));
  formular.append('_token', daten.token ?? '');

  try {
    const antwort = await anfrage(`${daten.base}/direkt/${daten.id}/speichern`, formular);

    buch.leeren();
    finger = antwort.finger ?? finger;

    bestaetigung = antwort.geaendert === 1
      ? '1 Änderung gespeichert'
      : `${antwort.geaendert} Änderungen gespeichert`;

    melden(bestaetigung, 'gut');

    // Neu laden, damit im Rahmen steht, was in der Datei steht.
    //
    // Das ist der zweite Teil des Nachweises: Was jetzt zu sehen ist,
    // kommt frisch von der Platte. Bleibt eine Änderung hier aus, war
    // sie nicht drin - und nicht bloss "irgendwo unterwegs".
    neuLaden();
  } catch (fehler) {
    melden(fehler.message, 'schlecht');
    if (speichernKnopf) speichernKnopf.disabled = false;

    if (fehler.neuLaden) {
      buch.leeren();
      neuLaden();
    }
  }
}

function neuLaden() {
  if (!rahmen) return;

  // Ein Anhängsel, damit der Browser wirklich neu holt.
  rahmen.src = `${daten.base}/direkt/${daten.id}/datei/${seite}?t=${Date.now()}`;
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

    const tiefe = seite.split('/').length - 1;
    const pfad = '../'.repeat(tiefe) + antwort.pfad;

    if (fuerStil) {
      const s = alsSchleier(lesen(fuerStil, 'backgroundImage').wert);
      setzen(fuerStil, 'backgroundImage', hintergrund(pfad, s.farbe, s.staerke));
      if (lesen(fuerStil, 'backgroundSize').wert === '') {
        setzen(fuerStil, 'backgroundSize', 'cover');
        setzen(fuerStil, 'backgroundPosition', 'center');
      }
      buch.merken(nummerVon(fuerStil), 'stil', { wert: fuerStil.getAttribute('style') ?? '' });
      blattZeigen();
    } else {
      ziel.setAttribute('src', pfad);
      ziel.removeAttribute('srcset');
      buch.merken(nummerVon(ziel), 'attribut', { name: 'src', wert: pfad });
    }

    offen();
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

    // Der Fehler, der diesen Umbau ausgelöst hat.
    //
    // Lädt der Rahmen neu - durch ein Skript der Kundenseite, eine
    // Weiterleitung, einen Cookie-Hinweis -, ist das DOM wieder auf
    // dem Stand der Datei. Vorher blieb der Editor stumm und schrieb
    // beim nächsten Speichern das Original zurück. Jetzt steht hier,
    // was los ist: Das Journal hat die Änderungen noch, im Rahmen sind
    // sie weg.
    if (buch.anzahl() > 0) {
      melden(
        `Die Seite im Rahmen wurde neu geladen – ${buch.anzahl()} Änderung(en) sind hier `
        + 'noch vorgemerkt, aber im Bild nicht mehr zu sehen. Speichern trägt sie in die '
        + 'Datei ein; Verwerfen wirft sie weg.',
        'warnung'
      );

      const streifen = document.querySelector('.wad-lautsprecher');
      streifen?.insertBefore(
        knopf('Verwerfen', 'wad-knopf wad-knopf--klein', () => {
          buch.leeren();
          offen();
          melden('bereit');
        }),
        streifen.lastElementChild
      );
    } else if (bestaetigung !== '') {
      // Das Neuladen gehört zum Speichern: Es holt die Datei, die
      // eben geschrieben wurde. Was zu sehen ist, ist also der Beleg
      // - und der Satz darüber bleibt stehen, bis der nächste
      // Handgriff ihn ablöst.
      melden(bestaetigung, 'gut');
      bestaetigung = '';
    } else {
      melden('bereit');
    }

    offen();
  });

  speichernKnopf?.addEventListener('click', speichern);

  bildfeld?.addEventListener('change', () => {
    bildTauschen(bildfeld.files?.[0]);
    bildfeld.value = '';
  });

  seitenwahl?.addEventListener('change', () => {
    if (buch.anzahl() > 0
      && !window.confirm(`${buch.anzahl()} Änderung(en) sind nicht gespeichert. Trotzdem wechseln?`)) {
      seitenwahl.value = seite;
      return;
    }

    seite = seitenwahl.value;
    buch.leeren();
    finger = '';
    abwaehlen();
    offen();
    rahmen.src = `${daten.base}/direkt/${daten.id}/datei/${seite}`;
  });

  window.addEventListener('beforeunload', (e) => {
    if (buch.anzahl() === 0) return;
    e.preventDefault();
    e.returnValue = '';
  });

  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      speichern();
    }
    if (e.key === 'Escape') abwaehlen();
  });

  window.addEventListener('resize', werkzeugeStellen);

  // Falls der Rahmen schon fertig war, bevor dieses Skript lief:
  // `load` kommt dann nicht mehr, und der Editor wäre stumm.
  if (rahmen.contentDocument?.readyState === 'complete') {
    herrichten(rahmen.contentDocument);
    melden('bereit');
  }
}
