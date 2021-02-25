Salut {{ $mail->dataOwnerName }},

--- Français ci-dessous ---

Wir haben soeben festgestellt, dass ein Kontakt direkt in Mailchimp hinzugefügt wurde. Dies führt zu Synchronisationsproblemen.
Bitte füge neue Kontakte immer nur im Webling ein, sie werden innert 24 Stunden zu Mailchimp synchronisiert.

Betroffener Kontakt: {{ $mail->contactFirstName }} {{ $mail->contactLastName }} ({{ $mail->contactEmail }})

Bitte füge den betroffenen Kontakt nun noch im Webling hinzu. Achte darauf, dass die Emailadresse mit obiger übereinstimmt und im Feld Email 1 steht. Danke.

Für Fragen sind wir gerne unter {{ $mail->adminEmail }} erreichbar.
Herzliche Grüsse,
Deine Mailchimp-Webling Anbindung ({{ $mail->configName }})

--- Deutsch oben ---

Nous venons de découvrir qu'un contact a été ajouté directement à Mailchimp. Cela entraîne des problèmes de synchronisation.
Veuillez n'ajouter que de nouveaux contacts dans Webling, ils seront synchronisés avec Mailchimp dans les prochaines 24 heures.

Contact affecté : {{ $mail->contactFirstName }} {{ $mail->contactLastName }} ({{ $mail->contactEmail }})

Veuillez ajouter maintenant le contact concerné à Webling. Assurez-vous que l'adresse e-mail correspond à ce qui précède et qu'elle se trouve dans le champ E-Mail 1. Je vous remercie.

Si vous avez des questions, veuillez nous contacter sous {{ $mail->adminEmail }}}.
Meilleures salutations,
Votre connexion Mailchimp-Webling ({{ $mail->configName }})