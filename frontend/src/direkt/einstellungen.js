/**
 * Die Einstellungsleiste zum gewählten Element.
 *
 * Geschrieben wird ausschliesslich in das `style`-Attribut des
 * Elements. Das ist keine Bequemlichkeit, sondern die einzige Art, die
 * bei einer fremden Website gutgeht:
 *
 * * Eine **Klasse** zu vergeben hiesse, ein Stilblatt mitzuliefern -
 *   und damit in eine Datei zu schreiben, in der schon jemand anders
 *   schreibt. Beim nächsten Herunterladen vom Kunden wäre sie weg oder
 *   doppelt.
 * * Das **Stilblatt zu ändern** wirkt auf alles, was dieselbe Klasse
 *   trägt. Wer einen Abschnitt einfärben will, färbt dann drei.
 *
 * Ein `style`-Attribut wirkt genau auf dieses eine Element, überschreibt
 * das Stilblatt des Kunden zuverlässig und lässt sich rückstandslos
 * wieder entfernen: Der Zurücksetzen-Knopf hier löscht wirklich, was
 * gesetzt wurde, statt eine zweite Regel darüberzulegen.
 *
 * Was hier nicht steht, ist Absicht. Es gibt kein Feld für die
 * Schriftart und keines für die Zeilenhöhe - beides gehört zum
 * Erscheinungsbild des Kunden, und wer es je Element verstellt, bekommt
 * eine Website, die an fünf Stellen anders aussieht als an den übrigen.
 */

/**
 * Die Felder der Leiste.
 *
 * `stil` ist die CSS-Eigenschaft, `art` bestimmt das Bedienelement.
 * Alles hier ist absichtlich flach - eine Liste, kein Baum: Die Leiste
 * soll man überfliegen können, nicht durchsuchen.
 */
export const FELDER = [
  {
    gruppe: 'Hintergrund',
    felder: [
      { stil: 'backgroundColor', name: 'Farbe', art: 'farbe' },
      { stil: 'backgroundImage', name: 'Bild', art: 'bild' },
    ],
  },
  {
    gruppe: 'Text',
    felder: [
      { stil: 'color', name: 'Farbe', art: 'farbe' },
      {
        stil: 'textAlign',
        name: 'Ausrichtung',
        art: 'wahl',
        werte: [['', 'wie bisher'], ['left', 'links'], ['center', 'mittig'], ['right', 'rechts']],
      },
      { stil: 'fontSize', name: 'Grösse', art: 'mass', min: 8, max: 120, schritt: 1, einheit: 'px' },
      {
        stil: 'fontWeight',
        name: 'Stärke',
        art: 'wahl',
        werte: [['', 'wie bisher'], ['400', 'normal'], ['600', 'halbfett'], ['700', 'fett']],
      },
    ],
  },
  {
    gruppe: 'Abstände',
    felder: [
      { stil: 'paddingTop', name: 'Innen oben', art: 'mass', min: 0, max: 240, schritt: 4, einheit: 'px' },
      { stil: 'paddingBottom', name: 'Innen unten', art: 'mass', min: 0, max: 240, schritt: 4, einheit: 'px' },
      { stil: 'marginTop', name: 'Aussen oben', art: 'mass', min: -120, max: 240, schritt: 4, einheit: 'px' },
      { stil: 'marginBottom', name: 'Aussen unten', art: 'mass', min: -120, max: 240, schritt: 4, einheit: 'px' },
    ],
  },
  {
    gruppe: 'Rahmen',
    felder: [
      { stil: 'borderRadius', name: 'Ecken', art: 'mass', min: 0, max: 80, schritt: 1, einheit: 'px' },
      { stil: 'opacity', name: 'Deckkraft', art: 'zahl', min: 0, max: 1, schritt: 0.05 },
    ],
  },
];

/**
 * Was gerade wirklich gilt.
 *
 * Zwei Quellen, und die Reihenfolge ist der ganze Punkt: Steht etwas im
 * `style`-Attribut, ist es das, was der Bearbeiter selbst gesetzt hat -
 * das gehört ins Feld. Steht dort nichts, wird der berechnete Wert
 * genommen, aber nur als Anzeige: Sonst stünde in jedem Feld ein Wert,
 * den niemand eingetragen hat, und ein Klick auf "zurücksetzen" wüsste
 * nicht mehr, wohin zurück.
 */
export function lesen(el, stil) {
  const eigen = el.style?.[stil] ?? '';

  if (eigen !== '') {
    return { wert: eigen, eigen: true };
  }

  const berechnet = el.ownerDocument.defaultView.getComputedStyle(el)[stil] ?? '';

  return { wert: berechnet, eigen: false };
}

/** Einen Wert setzen – oder ihn wieder herausnehmen. */
export function setzen(el, stil, wert) {
  if (wert === '' || wert === null || wert === undefined) {
    el.style.removeProperty(entstricheln(stil));
  } else {
    el.style[stil] = wert;
  }

  // Ein leer geräumtes style-Attribut bleibt sonst als style="" stehen
  // und landet so in der Datei des Kunden.
  if (el.getAttribute('style') === '') {
    el.removeAttribute('style');
  }
}

/** Alles zurücknehmen, was hier gesetzt wurde. */
export function zuruecksetzen(el) {
  for (const gruppe of FELDER) {
    for (const feld of gruppe.felder) {
      el.style.removeProperty(entstricheln(feld.stil));
    }
  }

  if (el.getAttribute('style') === '') {
    el.removeAttribute('style');
  }
}

/** Hat der Bearbeiter an diesem Element überhaupt etwas gesetzt? */
export function hatEigenes(el) {
  return FELDER.some((g) => g.felder.some((f) => (el.style?.[f.stil] ?? '') !== ''));
}

/**
 * Eine Farbe so, wie ein Farbwähler sie versteht.
 *
 * Der Wähler kennt nur `#rrggbb`. Eine Seite schreibt ihre Farben aber
 * als `rgb(...)`, und `transparent` ist gar keine Farbe - dafür steht
 * dann der Haken "keine" daneben statt eines irreführenden Schwarz.
 */
export function alsHexfarbe(wert) {
  const text = (wert ?? '').trim();

  if (text === '' || text === 'transparent' || text.startsWith('rgba(0, 0, 0, 0')) {
    return '';
  }

  if (/^#[0-9a-f]{6}$/i.test(text)) return text.toLowerCase();

  if (/^#[0-9a-f]{3}$/i.test(text)) {
    return '#' + text.slice(1).split('').map((z) => z + z).join('').toLowerCase();
  }

  const zahlen = text.match(/^rgba?\(([^)]+)\)$/i);

  if (!zahlen) return '';

  const teile = zahlen[1].split(/[,\s/]+/).filter((t) => t !== '').map(Number);

  if (teile.length < 3 || teile.slice(0, 3).some((z) => Number.isNaN(z))) return '';

  return '#' + teile.slice(0, 3)
    .map((z) => Math.max(0, Math.min(255, Math.round(z))).toString(16).padStart(2, '0'))
    .join('');
}

/** Die Zahl aus einem Mass wie "24px" – oder leer. */
export function alsZahl(wert) {
  const treffer = (wert ?? '').match(/^(-?[\d.]+)/);

  return treffer ? treffer[1] : '';
}

/** Die Adresse aus einem `url(...)` – oder leer. */
export function alsBildpfad(wert) {
  const treffer = (wert ?? '').match(/url\((['"]?)(.*?)\1\)/);

  return treffer ? treffer[2] : '';
}

/** backgroundColor -> background-color, für removeProperty. */
function entstricheln(stil) {
  return stil.replace(/[A-Z]/g, (z) => '-' + z.toLowerCase());
}
