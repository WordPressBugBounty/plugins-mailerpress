<?php

namespace MailerPress\Core\Workflows\Services;

class ContactResult
{
    public string $email;
    public ?int $contactId;
    public bool $isContact;
    public string $displayName;
    public string $firstName;
    public string $lastName;
    public ?object $contact;

    public function __construct(
        string $email,
        ?int $contactId,
        bool $isContact,
        string $displayName,
        string $firstName,
        string $lastName,
        ?object $contact = null
    ) {
        $this->email = $email;
        $this->contactId = $contactId;
        $this->isContact = $isContact;
        $this->displayName = $displayName;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->contact = $contact;
    }
}
