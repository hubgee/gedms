<?php

namespace App\Contracts;

/**
 * A cloud storage provider the application can store files in.
 *
 * A driver is more than a backend class: it also describes the settings an
 * administrator must supply, and optionally how to run an OAuth flow. The
 * admin UI renders itself from this description, so adding a provider does not
 * mean touching the controller or the Blade templates.
 *
 * To add a provider:
 *
 *   1. implement this interface (and {@see CloudOAuthProvider} when it uses
 *      OAuth);
 *   2. add the class to config/uploads.php under "drivers";
 *   3. done: it appears in the driver list with its own fields.
 */
interface CloudStorageDriver
{
    /**
     * Stable key stored in storage_spaces.storage_driver ("s3", "dropbox"...).
     */
    public function key(): string;

    /**
     * Human label shown in the driver select.
     */
    public function label(): string;

    /**
     * One-line explanation shown under the driver's fields.
     */
    public function description(): string;

    /**
     * Settings this driver understands.
     *
     * Each field is an array with:
     *   key         string   config key (also used as the input name)
     *   label       string
     *   type        string   text|password|number|checkbox|select|url
     *   required    bool     whether a fresh configuration must supply it
     *   secret      bool     never echoed back to the browser
     *   advanced    bool     hidden behind an "Advanced" disclosure
     *   default     mixed
     *   placeholder string
     *   help        string
     *   options     array<string,string>  for select fields
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array;

    /**
     * The OAuth provider when this driver can authorise interactively, or
     * null when the credentials are entered by hand (local, S3).
     */
    public function oauthProvider(): ?CloudOAuthProvider;

    /**
     * Build the byte store for a configuration.
     *
     * @param  array<string, mixed>  $config
     */
    public function build(array $config): UploadStorage;

    /**
     * Configuration-specific validation messages. An empty array means the
     * configuration is complete enough to try.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    public function validate(array $config): array;
}
