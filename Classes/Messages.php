<?php
namespace Areanet\PIM\Classes;


/**
 * Class Messages
 * @package Areanet\PIM\Classes
 */
class Messages
{
    const contentfly_general_access_denied              = 'contentfly_general_access_denied';

    /*
     * THE REJECTIONS AuthController DECIDES ITSELF (011-001-0004).
     *
     * They never reach the error handler, so until now they were the last group with a body of
     * their own: `{"message": "…"}` with a sentence in it and nothing to branch on. Each of them
     * is a foreseeable state, so each gets a key.
     *
     * `…_invalid_credentials` deliberately covers ALL 401s of the login — unknown user, wrong
     * password, rejected provider. The response must not be an oracle for which accounts exist;
     * one key for all of them keeps it that way (see AuthController, `$reject`).
     */
    const contentfly_general_invalid_credentials        = 'contentfly_general_invalid_credentials';
    const contentfly_general_invalid_refresh_token      = 'contentfly_general_invalid_refresh_token';
    const contentfly_general_too_many_attempts          = 'contentfly_general_too_many_attempts';
    const contentfly_general_jwt_not_configured         = 'contentfly_general_jwt_not_configured';

    // An upload whose name or content type is not accepted (000-000-0038).
    const contentfly_file_invalid_type                  = 'contentfly_file_invalid_type';
    const contentfly_file_too_large                     = 'contentfly_file_too_large';
    const contentfly_general_admin_not_deletable        = 'contentfly_general_admin_not_deletable';
    const contentfly_general_filesize_not_found         = 'contentfly_general_filesize_not_found';
    const contentfly_general_invalid_base_entity        = 'contentfly_general_invalid_base_entity';
    const contentfly_general_invalid_entity             = 'contentfly_general_invalid_entity';
    const contentfly_general_invalid_password           = 'contentfly_general_invalid_password';
    const contentfly_general_invalid_params             = 'contentfly_general_invalid_params';
    const contentfly_general_invalid_plugin_base        = 'contentfly_general_invalid_plugin_base';
    const contentfly_general_invalid_gettersetter       = 'contentfly_general_invalid_gettersetter';
    const contentfly_general_missing_params             = 'contentfly_general_missing_params';
    const contentfly_general_not_found                  = 'contentfly_general_not_found';
    // The installation has not been run — the guard in BaseControllerProvider (011-001-0003).
    const contentfly_general_not_installed              = 'contentfly_general_not_installed';
    const contentfly_general_permission_denied          = 'contentfly_general_permission_denied';
    const contentfly_general_plugin_not_found           = 'contentfly_general_plugin_not_found';
    const contentfly_general_property_not_exists        = 'contentfly_general_property_not_exists';
    const contentfly_general_ressource_already_exists   = 'contentfly_general_ressource_already_exists';
    const contentfly_general_user_already_exists        = 'contentfly_general_user_already_exists';
    const contentfly_general_unknown_perror             = 'contentfly_general_unknown_perror';
    const contentfly_general_unknown_entity             = 'contentfly_general_unknown_entity';
    const contentfly_general_unknown_property           = 'contentfly_general_unknown_property';
    const contentfly_general_invalid_sort_direction     = 'contentfly_general_invalid_sort_direction';
    const contentfly_general_unknown_plugin             = 'contentfly_general_unknown_plugin';
    const contentfly_general_unknown_type_object        = 'contentfly_general_unknown_type_object';
    const contentfly_general_use_plugin_register_method = 'contentfly_general_use_plugin_register_method';

    const contentfly_i18n_missing_lang_param            = 'contentfly_i18n_missing_lang_param';
    const contentfly_i18n_missing_translations          = 'contentfly_i18n_missing_translations';
    const contentfly_i18n_permission_denied             = 'contentfly_i18n_permission_denied';
    const contentfly_i18n_translations_exists           = 'contentfly_i18n_translations_exists';
    const contentfly_i18n_undefined_languages           = 'contentfly_i18n_undefined_languages';

    const contentfly_status_bad_request                 = 400;
    const contentfly_status_access_denied               = 403;
    const contentfly_status_invalid_token               = 401;
    const contentfly_status_not_found                   = 404;
    const contentfly_status_ressource_already_exists    = 409;
    const contentfly_status_server_error                = 500;
}