<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Base class of JSON actions.
*/

namespace Modules\MibBrowser\Actions;

use Modules\MibBrowser\Lib\I18n;

use CController,
	CControllerResponseData;

abstract class BaseJson extends CController {

	/** Minimal user type required. */
	protected int $min_user_type = USER_TYPE_ZABBIX_ADMIN;

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_FORM);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= $this->min_user_type;
	}

	/**
	 * Validate input with default validator, respond with error on failure.
	 */
	protected function validate(array $rules): bool {
		$ret = $this->validateInput($rules);

		if (!$ret) {
			$this->respondError(I18n::t('Invalid input parameters.'));
		}

		return $ret;
	}

	/**
	 * Send JSON response. Global frontend messages (for example environment warnings) are discarded here:
	 * failures are reported explicitly with respondError(), which includes the API error messages.
	 */
	protected function respond(array $data): void {
		get_and_clear_messages();

		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				| JSON_INVALID_UTF8_SUBSTITUTE
			)
		]));
	}

	protected function respondError(string $message, array $extra = []): void {
		$messages = array_column(get_and_clear_messages(), 'message');

		if ($messages) {
			$message .= "\n".implode("\n", $messages);
		}

		$this->respond(['error' => $message] + $extra);
	}
}
