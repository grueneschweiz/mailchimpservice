Salut {{ $mail->dataOwnerName }},

--- Français ci-dessous ---

Die Emailadresse von {{ $mail->contactFirstName }} {{ $mail->contactLastName }} ({{ $mail->contactEmail }}) scheint fake oder ungültig und wurde darum von Mailchimp abgelehnt. Bitte korrigiere die Emailadresse im WEBLING.

Für Fragen sind wir gerne unter {{ $mail->adminEmail }} erreichbar.
Herzliche Grüsse,
Deine Mailchimp-Webling Anbindung

--- Deutsch oben ---

L'adresse e-mail de {{ $mail->contactFirstName }} {{ $mail->contactLastName }} ({{ $mail->contactEmail }}) semble être fausse ou invalide et a donc été rejetée par Mailchimp. Veuillez corriger l'adresse e-mail dans le WEBLING.

Si vous avez des questions, veuillez nous contacter sous {{ $mail->adminEmail }}}.
Meilleures salutations,
Votre connexion Mailchimp-Webling