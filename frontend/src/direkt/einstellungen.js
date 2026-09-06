/**
 * Die Einstellungen zum gewählten Element.
 *
 * Aufgebaut wie in Elementor, weil das die Ordnung ist, die jeder
 * kennt, der schon einmal eine Seite gebaut hat:
 *
 *   Inhalt    - was das Element IST (Text, Verweisziel, Bild)
 *   Stil      - was man SIEHT (Hintergrund, Text, Rahmen)
 *   Erweitert - was man selten braucht (Abstände, Breite)
 *
 * Der dritte Reiter ist der Punkt: Abstände sind der häufigste Weg,
 * eine fremde Website zu verunstalten, und der seltenste Grund, eine
 * anzufassen. Sie liegen deshalb hinten und als **ein** Regler statt
 * vier Zahlenfeldern.
 *
 * Geschrieben wird ausschliesslich ins `style`-Attribut des Elements:
 *
 *   - Eine **Klasse** zu vergeben hiesse, ein Stilblatt mitzuliefern -
 *     also in eine Datei zu schreiben, in der schon jemand anders
 *     schreibt. Beim nächsten Herunterladen wäre sie weg oder doppelt.
 *   - Das **Stilblatt zu ändern** wirkt auf alles mit derselben Klasse.
 *     Wer einen Abschnitt einfärbt, färbt dann drei.
 *
 * Ein `style`-Attribut wirkt genau auf dieses eine Element und lässt
 * sich rückstandslos entfernen.
 */

/** Die drei Reiter, in der Reihenfolge, in der sie stehen. */
export const REITER = [
  { schluessel: 'inhalt', name: 'Inhalt' },
  { schluessel: 'stil', name: 'Stil' },
  { schluessel: 'mehr', name: 'Erweitert' },
];

/**
 * Die Felder je Reiter.
 *
 * `art` bestimmt das Bedienelement, `stil` die CSS-Eigenschaft. Was
 * hier nicht steht, ist Absicht: keine Schriftart, keine Zeilenhöhe,
 * keine Deckkraft. Alles drei gehört zum Erscheinungsbild der ganzen
 * Website - wer es je Element verstellt, bekommt eine Seite, die an
 * fünf Stellen anders aussieht als an den übrigen.
 */
export const FELDER = {
  inhalt: [
    { art: 'verweis', name: 'Verweisziel', nur: ['a'] },
    { art: 'bildtausch', name: 'Bild', nur: ['img'] },
    { art: 'hinweis', name: 'Text änderst du direkt in der Seite: anklicken und schreiben.' },
  ],

  stil: [
    { gruppe: 'Hintergrund' },
    { art: 'farbe', name: 'Farbe', stil: 'backgroundColor' },
    { art: 'bild', name: 'Bild', stil: 'backgroundImage' },

    // Das Farb-Overlay ist der einzige Wert, der aus zweien besteht.
    // Es liegt als Verlauf ueber dem Bild, im selben
    // `background-image` - so braucht es kein zweites Element und
    // keine Klasse.
    { art: 'overlay', name: 'Farbschleier über dem Bild' },
    {
      art: 'wahl', name: 'Ausschnitt', stil: 'backgroundSize',
      werte: [['', 'wie bisher'], ['cover', 'füllend'], ['contain', 'ganz zeigen']],
    },
    {
      art: 'wahl', name: 'Position', stil: 'backgroundPosition',
      werte: [['', 'wie bisher'], ['center', 'mittig'], ['top', 'oben'], ['bottom', 'unten']],
    },

    { gruppe: 'Text' },
    { art: 'farbe', name: 'Farbe', stil: 'color' },
    {
      art: 'knopfreihe', name: 'Ausrichtung', stil: 'textAlign',
      werte: [['left', '⯇'], ['center', '≡'], ['right', '⯈']],
    },
    { art: 'mass', name: 'Grösse', stil: 'fontSize', min: 8, max: 96, schritt: 1, einheit: 'px' },
    {
      art: 'knopfreihe', name: 'Stärke', stil: 'fontWeight',
      werte: [['400', 'normal'], ['600', 'halbfett'], ['700', 'fett']],
    },

    { gruppe: 'Rahmen' },
    { art: 'mass', name: 'Ecken', stil: 'borderRadius', min: 0, max: 60, schritt: 1, einheit: 'px' },
    {
      art: 'knopfreihe', name: 'Schatten', stil: 'boxShadow',
      werte: [
        ['', 'keiner'],
        ['0 4px 16px rgba(0,0,0,.12)', 'weich'],
        ['0 10px 34px rgba(0,0,0,.24)', 'stark'],
      ],
    },
  ],

  mehr: [
    { gruppe: 'Abstände' },

    // Ein Regler statt vier Feldern. Oben und unten zusammen: Wer sie
    // getrennt einstellt, macht das in neun von zehn Faellen gleich -
    // und im zehnten faellt es niemandem auf.
    { art: 'regler', name: 'Luft innen', stil: 'padding', min: 0, max: 120, schritt: 4, einheit: 'px' },
    { art: 'regler', name: 'Luft aussen', stil: 'marginBlock', min: 0, max: 120, schritt: 4, einheit: 'px' },

    { gruppe: 'Breite' },
    {
      art: 'knopfreihe', name: 'Breite', stil: 'maxWidth',
      werte: [['', 'voll'], ['72rem', 'eingerückt'], ['46rem', 'schmal']],
    },
    { art: 'zuruecksetzen', name: 'Alle Einstellungen zurücksetzen' },
  ],
};

/** Alle Eigenschaften, die hier gesetzt werden können. */
export function alleStile() {
  return Object.values(FELDER)
    .flat()
    .map((f) => f.stil)
    .filter(Boolean);
}

/**
 * Was gerade wirklich gilt.
 *
 * Zwei Quellen, und die Reihenfolge ist der Punkt: Steht etwas im
 * `style`-Attribut, hat der Bearbeiter es selbst gesetzt - das gehört
 * ins Feld. Sonst wird der berechnete Wert genommen, aber nur als
 * blasse Vorschau: Stünde er als echter Wert da, wüsste
 * "zurücksetzen" nicht mehr, wohin zurück.
 */
export function lesen(el, stil) {
  if (!el || !stil) return { wert: '', eigen: false };

  const eigen = el.style?.[stil] ?? '';

  if (eigen !== '') {
    return { wert: eigen, eigen: true };
  }

  const berechnet = el.ownerDocument?.defaultView?.getComputedStyle(el)?.[stil] ?? '';

  return { wert: berechnet, eigen: false };
}

/** Einen Wert setzen – oder ihn wieder herausnehmen. */
export function setzen(el, stil, wert) {
  if (wert === '' || wert === null || wert === undefined) {
    el.style.removeProperty(entstricheln(stil));
  } else {
    el.style[stil] = wert;
  }

  // Ein leergeräumtes style-Attribut bliebe sonst als style="" stehen
  // und landete so in der Datei des Kunden.
  if (el.getAttribute('style') === '') {
    el.removeAttribute('style');
  }
}

/** Alles zurücknehmen, was hier gesetzt wurde. */
export function zuruecksetzen(el) {
  alleStile().forEach((stil) => el.style.removeProperty(entstricheln(stil)));

  if (el.getAttribute('style') === '') {
    el.removeAttribute('style');
  }
}

/**
 * Hintergrundbild und Farbschleier zusammensetzen.
 *
 * Beide leben in derselben Eigenschaft: Der Verlauf steht vorn und
 * liegt damit über dem Bild. Zwei gleiche Farbstopps ergeben eine
 * gleichmässige Fläche - ein Verlauf, der keiner ist, und genau das
 * ist hier gewollt.
 */
export function hintergrund(bild, farbe, staerke) {
  const teile = [];

  if (farbe !== '' && staerke > 0) {
    const rgba = mitDeckkraft(farbe, staerke);
    teile.push(`linear-gradient(${rgba}, ${rgba})`);
  }

  if (bild !== '') {
    teile.push(`url("${bild}")`);
  }

  return teile.join(', ');
}

/** Die Adresse aus einem `url(...)` – oder leer. */
export function alsBildpfad(wert) {
  const treffer = (wert ?? '').match(/url\((['"]?)(.*?)\1\)/);

  return treffer ? treffer[2] : '';
}

/** Farbe und Stärke aus einem vorhandenen Schleier lesen. */
export function alsSchleier(wert) {
  const treffer = (wert ?? '').match(/linear-gradient\(\s*rgba?\(([^)]+)\)/i);

  if (!treffer) return { farbe: '', staerke: 0 };

  const teile = treffer[1].split(/[,\s/]+/).filter((t) => t !== '').map(Number);

  if (teile.length < 3) return { farbe: '', staerke: 0 };

  return {
    farbe: '#' + teile.slice(0, 3)
      .map((z) => Math.max(0, Math.min(255, Math.round(z))).toString(16).padStart(2, '0'))
      .join(''),
    staerke: teile.length > 3 ? Math.round(teile[3] * 100) : 100,
  };
}

/**
 * Eine Farbe so, wie ein Farbwähler sie versteht.
 *
 * Der Wähler kennt nur `#rrggbb`. Eine Seite schreibt ihre Farben aber
 * als `rgb(...)`, und `transparent` ist gar keine Farbe - dafür steht
 * dann nichts da statt eines irreführenden Schwarz.
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

// ------------------------------------------------------------------

function mitDeckkraft(hex, prozent) {
  const h = hex.replace('#', '');
  const zahl = parseInt(h.length === 3 ? h.split('').map((z) => z + z).join('') : h, 16);

  return `rgba(${(zahl >> 16) & 255}, ${(zahl >> 8) & 255}, ${zahl & 255}, ${(prozent / 100).toFixed(2)})`;
}

/** backgroundColor -> background-color, für removeProperty. */
function entstricheln(stil) {
  return stil.replace(/[A-Z]/g, (z) => '-' + z.toLowerCase());
}
