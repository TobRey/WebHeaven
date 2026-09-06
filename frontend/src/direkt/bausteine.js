/**
 * Was sich in eine fremde Seite einsetzen lässt.
 *
 * Die eine Regel, an der hier alles hängt: **Ein Baustein bringt keine
 * eigene Gestaltung mit.** Kein Klassenname, keine Farbe, keine
 * Schriftart, kein Rahmen. Er ist blankes, sinnvolles HTML - eine
 * Überschrift ist ein `<h2>`, ein Knopf ein `<a>`.
 *
 * Der Grund ist der Gegenstand: Die Seite gehört einem Kunden und hat
 * ihr eigenes Stilblatt. Ein Baustein mit mitgebrachten Farben sähe auf
 * jeder zweiten Website falsch aus - und wer ihn zurechtrücken wollte,
 * müsste gegen das Stilblatt des Kunden anschreiben. So erbt er
 * stattdessen, was um ihn herum gilt, und passt von selbst.
 *
 * Was hier trotzdem als `style` steht, ist Mass und nicht Geschmack:
 * eine Mindesthöhe, damit ein leerer Abstandhalter greifbar bleibt;
 * `max-width` bei Bildern, damit ein grosses nicht aus dem Layout
 * läuft. Alles davon lässt sich in der Einstellungsleiste ändern.
 */

/**
 * Die Bausteine, in der Reihenfolge, in der sie in der Leiste stehen.
 *
 * `bild: true` heisst: Nach dem Einsetzen fragt der Editor gleich nach
 * einer Datei. Ein Bildplatzhalter, den man erst noch anklicken muss,
 * ist ein Zwischenschritt, den niemand braucht.
 */
export const BAUSTEINE = [
  {
    art: 'ueberschrift',
    name: 'Überschrift',
    zeichen: 'H',
    html: '<h2>Neue Überschrift</h2>',
  },
  {
    art: 'text',
    name: 'Text',
    zeichen: '¶',
    html: '<p>Hier steht ein neuer Absatz. Klick hinein und schreib, was dort stehen soll.</p>',
  },
  {
    art: 'bild',
    name: 'Bild',
    zeichen: '▨',
    bild: true,
    html: '<img alt="" style="max-width:100%;height:auto">',
  },
  {
    art: 'knopf',
    name: 'Knopf',
    zeichen: '▭',
    // Ein <a> und kein <button>: Auf einer Website führt ein Knopf
    // fast immer irgendwohin, und ein <a> erbt die Knopfgestaltung
    // des Kunden, wo eine da ist.
    html: '<a href="#">Jetzt anfragen</a>',
  },
  {
    art: 'liste',
    name: 'Liste',
    zeichen: '≡',
    html: '<ul><li>Erster Punkt</li><li>Zweiter Punkt</li><li>Dritter Punkt</li></ul>',
  },
  {
    art: 'zwei-spalten',
    name: 'Zwei Spalten',
    zeichen: '◫',
    // Grid und nicht Float: Es braucht keine Aufräumzeile dahinter und
    // bricht auf dem Telefon von selbst um.
    html: '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:24px">'
      + '<div><h3>Links</h3><p>Text der linken Spalte.</p></div>'
      + '<div><h3>Rechts</h3><p>Text der rechten Spalte.</p></div>'
      + '</div>',
  },
  {
    art: 'abschnitt',
    name: 'Abschnitt',
    zeichen: '▤',
    html: '<section style="padding:64px 24px">'
      + '<h2>Neuer Abschnitt</h2>'
      + '<p>Der Platz für den nächsten Gedanken.</p>'
      + '</section>',
  },
  {
    art: 'trenner',
    name: 'Trenner',
    zeichen: '—',
    html: '<hr>',
  },
  {
    art: 'abstand',
    name: 'Abstand',
    zeichen: '↕',
    html: '<div style="height:64px"></div>',
  },
];

/**
 * Einen Baustein zu einem Element machen.
 *
 * Über ein `<template>`, damit der Browser das HTML einmal richtig
 * liest: `innerHTML` auf einem `<div>` würde ein `<li>` ohne seine
 * Liste stillschweigend wegwerfen.
 */
export function bauen(dok, art) {
  const bauplan = BAUSTEINE.find((b) => b.art === art);

  if (!bauplan) return null;

  const vorlage = dok.createElement('template');
  vorlage.innerHTML = bauplan.html;

  const el = vorlage.content.firstElementChild;

  return el ? dok.importNode(el, true) : null;
}

/** Braucht dieser Baustein gleich nach dem Einsetzen ein Bild? */
export function willBild(art) {
  return BAUSTEINE.find((b) => b.art === art)?.bild === true;
}
