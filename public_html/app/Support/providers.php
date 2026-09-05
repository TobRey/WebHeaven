<?php

/**
 * Wo findet man bei welchem Anbieter was?
 *
 * Beim Hosting stehen hier nur noch die Vorgaben – Übertragungsart,
 * Port, Verzeichnis –, mit denen ein Formular vorbelegt wird. Die
 * ausklappbaren Schritt-für-Schritt-Anleitungen sind weg: Sie standen
 * über den Feldern und wurden nicht gelesen, und die Beispiele stehen
 * jetzt in den Feldern selbst, wo die Frage entsteht.
 *
 * Beim Domain-Umzug ist das anders – dort ist die Anleitung der Inhalt
 * der Seite und nicht die Wand davor.
 *
 * Hinweis: Anbieter bauen ihre Oberflächen gelegentlich um. Die Angaben
 * beschreiben deshalb den Weg ("Verwaltung → Domains → ..."), nicht die
 * genaue Beschriftung eines Knopfes.
 */

declare(strict_types=1);

return [

    // ------------------------------------------------------------------
    // Hosting: wo bekomme ich die FTP-Zugangsdaten?
    // ------------------------------------------------------------------

    'hosting' => [
        'cpanel' => [
            'name' => 'cPanel (viele Anbieter)',
            'protocol' => 'ftp',
            'port' => 21,
            'path' => '/',
        ],

        'godaddy' => [
            'name' => 'GoDaddy (cPanel)',
            'protocol' => 'ftp',
            'port' => 21,
            // "/" und nicht "/public_html": Ein FTP-Unterkonto sitzt
            // bereits in seinem Ordner, und so eines legt man bei
            // GoDaddy je Website an.
            'path' => '/',
        ],

        'plesk' => [
            'name' => 'Plesk',
            'protocol' => 'ftp',
            'port' => 21,
            'path' => '/httpdocs',
        ],

        'hostpoint' => [
            'name' => 'Hostpoint (Schweiz)',
            'protocol' => 'sftp',
            'port' => 22,
            'path' => '/www',
        ],

        'infomaniak' => [
            'name' => 'Infomaniak (Schweiz)',
            'protocol' => 'sftp',
            'port' => 22,
            'path' => '/web',
        ],

        'ionos' => [
            'name' => 'IONOS / 1&1',
            'protocol' => 'sftp',
            'port' => 22,
            'path' => '/',
        ],

        'strato' => [
            'name' => 'Strato',
            'protocol' => 'ftp',
            'port' => 21,
            'path' => '/',
        ],

        'hostinger' => [
            'name' => 'Hostinger',
            'protocol' => 'ftp',
            'port' => 21,
            'path' => '/public_html',
        ],

        'other' => [
            'name' => 'Anderer Anbieter',
            'protocol' => 'ftp',
            'port' => 21,
            'path' => '/public_html',
        ],
    ],

    // ------------------------------------------------------------------
    // Registrare: wo bekomme ich den Auth-Code, wo hebe ich die Sperre auf,
    // wo stelle ich die Nameserver oder den A-Eintrag um?
    // ------------------------------------------------------------------

    'registrar' => [
        'godaddy' => [
            'name' => 'GoDaddy',
            'authcode' => 'Meine Produkte → bei der Domain auf <strong>Domain-Einstellungen</strong> → ganz unten <strong>Autorisierungscode abrufen</strong>. Der Code kommt per E-Mail.',
            'lock' => 'Meine Produkte → Domain-Einstellungen → <strong>Domänensperre</strong> ausschalten.',
            'dns' => 'Meine Produkte → bei der Domain auf <strong>DNS</strong> → Eintrag vom Typ <strong>A</strong> mit Name <code>@</code> bearbeiten.',
            'nameserver' => 'Meine Produkte → Domain-Einstellungen → <strong>Nameserver</strong> → „Eigene Nameserver verwenden".',
        ],
        'hostpoint' => [
            'name' => 'Hostpoint',
            'authcode' => 'Control Panel → <strong>Domains</strong> → Domain wählen → <strong>Transfer / Auth-Code</strong>.',
            'lock' => 'Control Panel → Domains → Domain wählen → <strong>Transfer-Sperre</strong> aufheben.',
            'dns' => 'Control Panel → Domains → Domain wählen → <strong>DNS-Einträge</strong> → A-Eintrag für <code>@</code>.',
            'nameserver' => 'Control Panel → Domains → Domain wählen → <strong>Nameserver</strong>.',
        ],
        'infomaniak' => [
            'name' => 'Infomaniak',
            'authcode' => 'Manager → <strong>Domains</strong> → Domain wählen → <strong>Transfer</strong> → Auth-Code anzeigen.',
            'lock' => 'Manager → Domains → Domain wählen → <strong>Sicherheit</strong> → Transfersperre deaktivieren.',
            'dns' => 'Manager → Domains → Domain wählen → <strong>DNS-Zone</strong> → A-Eintrag bearbeiten.',
            'nameserver' => 'Manager → Domains → Domain wählen → <strong>Nameserver</strong>.',
        ],
        'switch' => [
            'name' => 'SWITCH (.ch direkt)',
            'authcode' => 'Bei .ch-Domains vergibt der jeweilige Registrar den Auth-Code, nicht SWITCH direkt. Beim Anbieter anfragen, über den die Domain läuft.',
            'lock' => 'Ebenfalls beim Registrar; .ch-Domains haben oft keine zusätzliche Sperre.',
            'dns' => 'Über den Registrar, bei dem die Domain verwaltet wird.',
            'nameserver' => 'Über den Registrar, bei dem die Domain verwaltet wird.',
        ],
        'ionos' => [
            'name' => 'IONOS / 1&1',
            'authcode' => 'Kundenkonto → <strong>Domains &amp; SSL</strong> → Domain wählen → <strong>Auth-Code anfordern</strong>.',
            'lock' => 'Domains &amp; SSL → Domain wählen → <strong>Transfer-Schutz</strong> aufheben.',
            'dns' => 'Domains &amp; SSL → Domain wählen → <strong>DNS</strong> → A-Eintrag bearbeiten.',
            'nameserver' => 'Domains &amp; SSL → Domain wählen → <strong>Nameserver</strong> anpassen.',
        ],
        'strato' => [
            'name' => 'Strato',
            'authcode' => 'Kundenlogin → <strong>Domainverwaltung</strong> → Domain wählen → <strong>Auth-Code anfordern</strong>.',
            'lock' => 'Domainverwaltung → Domain wählen → Transfersperre deaktivieren.',
            'dns' => 'Domainverwaltung → Domain wählen → <strong>DNS-Einstellungen</strong>.',
            'nameserver' => 'Domainverwaltung → Domain wählen → <strong>Nameserver</strong>.',
        ],
        'namecheap' => [
            'name' => 'Namecheap',
            'authcode' => 'Domain List → <strong>Manage</strong> → Sharing &amp; Transfer → <strong>Auth Code</strong>.',
            'lock' => 'Domain List → Manage → <strong>Registrar Lock</strong> ausschalten.',
            'dns' => 'Domain List → Manage → <strong>Advanced DNS</strong> → A-Record.',
            'nameserver' => 'Domain List → Manage → <strong>Nameservers</strong>.',
        ],
        'other' => [
            'name' => 'Anderer Registrar',
            'authcode' => 'Im Kundenbereich nach <strong>Auth-Code</strong>, <strong>EPP-Code</strong> oder <strong>Transfer</strong> suchen. Meist bei der Domain selbst.',
            'lock' => 'Nach <strong>Transfer-Sperre</strong>, <strong>Domain Lock</strong> oder <strong>Transfer-Schutz</strong> suchen und ausschalten.',
            'dns' => 'Nach <strong>DNS</strong>, <strong>DNS-Zone</strong> oder <strong>Namenseinträge</strong> suchen.',
            'nameserver' => 'Nach <strong>Nameserver</strong> suchen; dort lassen sich eigene eintragen.',
        ],
    ],
];
