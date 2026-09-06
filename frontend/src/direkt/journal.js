/**
 * Was geändert wurde - notiert, sobald es geschieht.
 *
 * Das ist der Kern des Umbaus, und er kommt aus einem Fehler, der lange
 * unsichtbar war: Der alte Editor las beim Speichern das Dokument aus
 * dem Rahmen aus und schickte es als Ganzes zurück.
 *
 * Das geht so lange gut, wie im Rahmen noch steht, was der Bearbeiter
 * hineingeschrieben hat. Eine Kundenwebsite bringt aber ihr eigenes
 * JavaScript mit - ein Cookie-Hinweis, eine Weiterleitung, ein
 * `location.reload()`, ein `<meta http-equiv="refresh">`. Lädt der
 * Rahmen dadurch neu, ist die Arbeit weg. Und dann passierte das
 * Schlimmste, was ein Werkzeug tun kann: Beim Speichern wurde das
 * frisch geladene **Original** ausgelesen, zurückgeschrieben - und
 * "gespeichert" gemeldet. Nichts war falsch, ausser dem Ergebnis.
 *
 * Deshalb wird hier nicht mehr am Ende abgelesen, sondern **beim
 * Entstehen notiert**. Das Journal liegt neben dem Rahmen, nicht darin.
 * Es überlebt, was im Rahmen passiert - und wenn der Rahmen neu lädt,
 * lässt sich sagen, was noch offen ist, statt es stillschweigend zu
 * verlieren.
 *
 * Eine Änderung je Element und Art: Wer denselben Absatz dreimal
 * ändert, hat am Ende einen Eintrag mit dem letzten Stand, nicht drei.
 */
export function journal() {
  /** @type {Map<string, object>} */
  const eintraege = new Map();

  return {
    /**
     * Etwas vermerken.
     *
     * Der Schlüssel ist Nummer und Art zusammen: Ein Absatz kann
     * gleichzeitig neuen Text und einen neuen Hintergrund haben, und
     * beides muss stehenbleiben.
     */
    merken(id, was, daten) {
      if (id === null || id === undefined || id === '') return;
      eintraege.set(`${id}:${was}`, { id: Number(id), was, ...daten });
    },

    /**
     * Etwas zurücknehmen.
     *
     * Wird gebraucht, wenn ein Element gelöscht wird: Seine
     * Textänderung ist dann gegenstandslos, und der Server würde
     * sonst versuchen, in etwas zu schreiben, das er gleich darauf
     * entfernt.
     */
    vergessen(id) {
      for (const schluessel of [...eintraege.keys()]) {
        if (schluessel.startsWith(`${id}:`)) eintraege.delete(schluessel);
      }
    },

    liste() {
      return [...eintraege.values()];
    },

    anzahl() {
      return eintraege.size;
    },

    leeren() {
      eintraege.clear();
    },

    /** Welche Elemente sind betroffen? Für die Warnung beim Neuladen. */
    nummern() {
      return [...new Set([...eintraege.values()].map((e) => e.id))];
    },
  };
}
