/**
 * Blöcke greifen, tauschen und einsetzen.
 *
 * Der Unterschied zum Ziehen im Abschnittseditor ist keine Kleinigkeit,
 * sondern folgt aus dem Gegenstand: Dort liegt ein Datenmodell darunter,
 * und ein Abschnitt darf zwischen zwei andere. Hier liegt fremdes HTML
 * darunter, von dem niemand weiss, wie es gebaut ist - ein Element
 * zwischen zwei andere zu schieben, hiesse raten, wo es hin darf.
 *
 * Deshalb: **Ziehen tauscht.** Wer einen Block auf einen anderen zieht,
 * vertauscht die beiden. Das geht in jede Richtung - untereinander
 * genauso wie nebeneinander -, ohne dass das Werkzeug das Layout
 * verstehen muss. Zwei Elemente, die nebeneinander stehen, stehen
 * danach immer noch nebeneinander, nur andersherum.
 *
 * Einsetzen ist die Ausnahme davon: Ein neuer Baustein aus der Leiste
 * hat noch keinen Platz, den er räumen könnte. Er kommt vor oder hinter
 * den Block, über dem er losgelassen wird - je nachdem, welche Hälfte
 * der Zeiger trifft.
 */

/** Wie lange der Finger stillhalten muss, bevor der Zug beginnt. */
const HALTEN_MS = 250;

/** Wie weit die Maus sich bewegen muss, bevor aus dem Klick ein Zug wird. */
const SCHWELLE_PX = 5;

/** Wie nah am Rand die Seite von selbst weiterrollt. */
const ROLLZONE_PX = 90;

/** Wie schnell sie das tut. */
const ROLLTEMPO = 14;

/**
 * Das Ziehen im Rahmen verdrahten.
 *
 * @param {object} o
 * @param {Document} o.dokument   das Dokument im Rahmen
 * @param {Function} o.griffe     () => Auswahl-Zeichenkette für die Ziehgriffe
 * @param {Function} o.block      (element) => der Block, der bewegt wird
 * @param {Function} o.kandidaten () => alle Blöcke, die als Ziel taugen
 * @param {Function} o.getauscht  (a, b) => void
 * @param {Function} [o.beginnt]  (element) => void
 * @param {Function} [o.beendet]  () => void
 */
export function greifbar(o) {
  const dok = o.dokument;
  let zug = null;

  dok.addEventListener('pointerdown', beginn, { passive: false });

  function beginn(e) {
    if (e.button !== 0) return;

    const griff = e.target.closest?.(o.griffe());
    if (!griff) return;

    const block = o.block(griff);
    if (!block) return;

    e.preventDefault();

    zug = {
      block,
      griff,
      zeiger: e.pointerId,
      startX: e.clientX,
      startY: e.clientY,
      finger: e.pointerType !== 'mouse',
      laeuft: false,
      warten: null,
      ziele: [],
      ziel: null,
      geist: null,
      rollen: 0,
    };

    if (zug.finger) {
      zug.warten = setTimeout(() => starten(e), HALTEN_MS);
    }

    dok.addEventListener('pointermove', bewegen, { passive: false });
    dok.addEventListener('pointerup', ende);
    dok.addEventListener('pointercancel', abbrechen);
  }

  function starten(e) {
    if (!zug || zug.laeuft) return;

    zug.laeuft = true;

    // Die Kandidaten einmal einsammeln, nicht bei jeder Bewegung. Auf
    // einer Seite mit dreihundert Elementen wäre das Ziehen sonst zäh.
    zug.ziele = o.kandidaten().filter((el) => el !== zug.block && !zug.block.contains(el));

    dok.documentElement.setAttribute('data-wa-zieht', '');
    zug.block.setAttribute('data-wa-gezogen', '');

    zug.geist = dok.createElement('div');
    zug.geist.setAttribute('data-wa-direkt', '');
    zug.geist.className = 'wa-geist';
    zug.geist.textContent = beschriften(zug.block);
    dok.body.appendChild(zug.geist);

    o.beginnt?.(zug.block);

    try {
      zug.griff.setPointerCapture?.(e.pointerId);
    } catch {
      /* Manche Browser verweigern das mitten im Zug - dann eben ohne. */
    }

    zeigen(e.clientX, e.clientY);
  }

  function bewegen(e) {
    if (!zug || e.pointerId !== zug.zeiger) return;

    const weit = Math.hypot(e.clientX - zug.startX, e.clientY - zug.startY);

    if (!zug.laeuft) {
      if (zug.finger) {
        // Vor Ablauf der Wartezeit ist eine Bewegung ein Wischen und
        // kein Zug - sonst liesse sich die Seite nicht mehr rollen.
        if (weit > 12) abbrechen();
        return;
      }

      if (weit < SCHWELLE_PX) return;

      starten(e);
    }

    e.preventDefault();
    zeigen(e.clientX, e.clientY);
    rollen(e.clientY);
  }

  /**
   * Den Block unter dem Zeiger suchen.
   *
   * Nicht den nächstgelegenen, sondern den, auf dem der Zeiger wirklich
   * steht - und davon den kleinsten. Eine Seite ist ineinander
   * geschachtelt; über einer Überschrift steht der Zeiger immer auch
   * über deren Abschnitt und über dem Rumpf. Gemeint ist die
   * Überschrift.
   */
  function zeigen(x, y) {
    if (!zug?.laeuft) return;

    zug.geist.style.transform = `translate(${x + 14}px, ${y + 14}px)`;

    let treffer = null;
    let flaeche = Infinity;

    for (const el of zug.ziele) {
      const k = el.getBoundingClientRect();

      if (k.width < 1 || k.height < 1) continue;
      if (x < k.left || x > k.right || y < k.top || y > k.bottom) continue;

      const gross = k.width * k.height;

      if (gross < flaeche) {
        flaeche = gross;
        treffer = el;
      }
    }

    if (treffer === zug.ziel) return;

    zug.ziel?.removeAttribute('data-wa-ziel');
    zug.ziel = treffer;
    zug.ziel?.setAttribute('data-wa-ziel', '');
  }

  /** Am Rand von selbst weiterrollen, damit auch Fernes erreichbar ist. */
  function rollen(y) {
    const hoehe = dok.defaultView.innerHeight;
    let tempo = 0;

    if (y < ROLLZONE_PX) tempo = -ROLLTEMPO;
    else if (y > hoehe - ROLLZONE_PX) tempo = ROLLTEMPO;

    if (tempo === zug.rollen) return;

    zug.rollen = tempo;

    if (zug.rollTimer) {
      clearInterval(zug.rollTimer);
      zug.rollTimer = null;
    }

    if (tempo !== 0) {
      zug.rollTimer = setInterval(() => dok.defaultView.scrollBy(0, tempo), 16);
    }
  }

  function ende() {
    if (!zug) return;

    const { block, ziel, laeuft } = zug;
    aufraeumen();

    if (laeuft && ziel && ziel !== block) {
      o.getauscht(block, ziel);
    }
  }

  function abbrechen() {
    aufraeumen();
  }

  function aufraeumen() {
    if (!zug) return;

    clearTimeout(zug.warten);
    if (zug.rollTimer) clearInterval(zug.rollTimer);

    zug.geist?.remove();
    zug.block.removeAttribute('data-wa-gezogen');
    zug.ziel?.removeAttribute('data-wa-ziel');
    dok.documentElement.removeAttribute('data-wa-zieht');

    dok.removeEventListener('pointermove', bewegen);
    dok.removeEventListener('pointerup', ende);
    dok.removeEventListener('pointercancel', abbrechen);

    o.beendet?.();
    zug = null;
  }
}

/**
 * Zwei Elemente die Plätze tauschen lassen.
 *
 * Über einen Platzhalter, weil `insertBefore` das Element aus seiner
 * alten Stelle nimmt: Ohne die Marke wäre die zweite Stelle nach dem
 * ersten Schritt eine andere, und bei benachbarten Elementen käme etwas
 * anderes heraus als gemeint.
 */
export function tauschen(a, b) {
  const marke = a.ownerDocument.createComment('wa-tausch');

  a.parentNode?.insertBefore(marke, a);
  b.parentNode?.insertBefore(a, b);
  marke.parentNode?.insertBefore(b, marke);
  marke.remove();
}

/**
 * Ein Element in Leserichtung verschieben.
 *
 * Der Weg für alle, denen Ziehen zu fummelig ist - und der einzige, der
 * bei zittriger Hand verlässlich funktioniert. Es tauscht mit dem
 * Nachbarn, bleibt also innerhalb desselben Elternelements: Ein Block
 * springt nicht unversehens in einen anderen Abschnitt.
 */
export function schieben(el, richtung) {
  const nachbar = richtung < 0
    ? el.previousElementSibling
    : el.nextElementSibling;

  if (!nachbar) return false;

  tauschen(el, nachbar);

  return true;
}

/** Was im Schatten am Zeiger steht. */
function beschriften(el) {
  const name = el.tagName.toLowerCase();
  const text = (el.textContent ?? '').trim().replace(/\s+/g, ' ');

  return text === '' ? name : `${name} · ${text.slice(0, 28)}`;
}
