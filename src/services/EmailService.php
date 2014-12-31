<?php
namespace craft\app\services;

use \craft\app\components\BaseComponent;
use \craft\app\models\Email              as EmailModel;
use \craft\app\models\User               as UserModel;

/**
 * EmailService provides APIs for sending email in Craft.
 *
 * An instance of EmailService is globally accessible in Craft via {@link WebApp::email `craft()->email`}.
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2014, Pixel & Tonic, Inc.
 * @license   http://buildwithcraft.com/license Craft License Agreement
 * @see       http://buildwithcraft.com
 * @package   craft.app.services
 * @since     1.0
 */
class EmailService extends BaseComponent
{
	// Properties
	// =========================================================================

	/**
	 * @var
	 */
	private $_settings;

	/**
	 * @var int
	 */
	private $_defaultEmailTimeout = 10;

	// Public Methods
	// =========================================================================

	/**
	 * Sends an email based on the attributes of the given {@link EmailModel}.
	 *
	 * Emails sent with sendEmail() will have both plain text and HTML bodies, leaving it up to the email client to
	 * decide which one to show.
	 *
	 * The plain text body’s template is defined by the EmailModel’s {@link EmailModel::body body} attribute, and the
	 * HTML body’s template is defined by the {@link EmailModel::htmlBody htmlBody} attribute. If the htmlBody attribute
	 * is not set, then the HTML body will be automatically generated by running the plain text body through
	 * {@link StringHelper::parseMarkdown() Markdown}.
	 *
	 * Both the plain text and HTML bodies of the email will be rendered with Twig. A `user` variable will be available
	 * to them, which will be set to a {@link UserModel} representing the user that the email is getting sent to
	 * (identified by the EmailModel’s {@link EmailModel::toEmail toEmail} attribute). Any variables passed into
	 * sendEmail()’s $variables argument will alse be made available to the templates.
	 *
	 * ```php
	 * $email = new EmailModel();
	 * $email->toEmail = 'hello@example.com';
	 * $email->subject = 'Heyyyyy';
	 * $email->body    = 'How you doin, {{ user.name }}?';
	 *
	 * craft()->email->sendEmail($email);
	 * ```
	 *
	 * @param EmailModel $emailModel The EmailModel object that defines information about the email to be sent.
	 * @param array      $variables  Any variables that should be made available to the email’s plain text and HTML
	 *                               bodies as they are being rendered with Twig, in addition to the `user` variable.
	 *
	 * @return bool Whether the email was successfully sent.
	 */
	public function sendEmail(EmailModel $emailModel, $variables = array())
	{
		$user = craft()->users->getUserByEmail($emailModel->toEmail);

		if (!$user)
		{
			$user = new UserModel();
			$user->email = $emailModel->toEmail;
			$user->firstName = $emailModel->toFirstName;
			$user->lastName = $emailModel->toLastName;
		}

		return $this->_sendEmail($user, $emailModel, $variables);
	}

	/**
	 * Sends an email by a given key.
	 *
	 * Craft has four predefined email keys: account_activation, verify_new_email, forgot_password, and test_email.
	 *
	 * Plugins can register additional email keys using the
	 * [registerEmailMessages](http://buildwithcraft.com/docs/plugins/hooks-reference#registerEmailMessages) hook, and
	 * by providing the corresponding language strings.
	 *
	 * ```php
	 * craft()->email->sendEmailByKey($user, 'account_activation', array(
	 *     'link' => $activationUrl
	 * ));
	 * ```
	 *
	 * @param UserModel $user      The user that should receive the email.
	 * @param string    $key       The email key.
	 * @param array     $variables Any variables that should be passed to the email body template.
	 *
	 * @throws Exception
	 * @return bool Whether the email was successfully sent.
	 */
	public function sendEmailByKey(UserModel $user, $key, $variables = array())
	{
		$emailModel = new EmailModel();

		if (craft()->getEdition() >= Craft::Client)
		{
			$message = craft()->emailMessages->getMessage($key, $user->preferredLocale);

			$emailModel->subject  = $message->subject;
			$emailModel->body     = $message->body;
		}
		else
		{
			$emailModel->subject  = Craft::t($key.'_subject', null, null, 'en_us');
			$emailModel->body     = Craft::t($key.'_body', null, null, 'en_us');
		}

		$tempTemplatesPath = '';

		if (craft()->getEdition() >= Craft::Client)
		{
			// Is there a custom HTML template set?
			$settings = $this->getSettings();

			if (!empty($settings['template']))
			{
				$tempTemplatesPath = craft()->path->getSiteTemplatesPath();
				$template = $settings['template'];
			}
		}

		if (empty($template))
		{
			$tempTemplatesPath = craft()->path->getCpTemplatesPath();
			$template = '_special/email';
		}

		if (!$emailModel->htmlBody)
		{
			// Auto-generate the HTML content
			$emailModel->htmlBody = StringHelper::parseMarkdown($emailModel->body);
		}

		$emailModel->htmlBody = "{% extends '{$template}' %}\n".
			"{% set body %}\n".
			$emailModel->htmlBody.
			"{% endset %}\n";

		// Temporarily swap the templates path
		$originalTemplatesPath = craft()->path->getTemplatesPath();
		craft()->path->setTemplatesPath($tempTemplatesPath);

		// Send the email
		$return = $this->_sendEmail($user, $emailModel, $variables);

		// Return to the original templates path
		craft()->path->setTemplatesPath($originalTemplatesPath);

		return $return;
	}

	/**
	 * Returns the system email settings defined in Settings → Email.
	 *
	 * @return array The system email settings.
	 */
	public function getSettings()
	{
		if (!isset($this->_settings))
		{
			$this->_settings = craft()->systemSettings->getSettings('email');
		}

		return $this->_settings;
	}

	/**
	 * Sends a test email using the given settings.
	 *
	 * @param array $settings The email settings to test.
	 *
	 * @return bool Whether the email was successfully sent.
	 */
	public function sendTestEmail($settings)
	{
		$originalSettings = $this->_settings;

		$this->_settings = $settings;

		$user = craft()->userSession->getUser();
		$newSettings = array();

		foreach ($settings as $key => $value)
		{
			if ($key == 'password' && $value)
			{
				$value = 'xxxxx';
			}

			$newSettings[$key] = $value;
		}

		$success = $this->sendEmailByKey($user, 'test_email', array('settings' => $newSettings));

		$this->_settings = $originalSettings;

		return $success;
	}

	/**
	 * Fires an 'onBeforeSendEmail' event.
	 *
	 * @param Event $event
	 */
	public function onBeforeSendEmail(Event $event)
	{
		$this->raiseEvent('onBeforeSendEmail', $event);
	}

	/**
	 * Fires an 'onSendEmail' event.
	 *
	 * @param Event $event
	 */
	public function onSendEmail(Event $event)
	{
		$this->raiseEvent('onSendEmail', $event);
	}

	// Private Methods
	// =========================================================================

	/**
	 * @param UserModel  $user
	 * @param EmailModel $emailModel
	 * @param array      $variables
	 *
	 * @throws Exception
	 * @return bool
	 */
	private function _sendEmail(UserModel $user, EmailModel $emailModel, $variables = array())
	{
		// Get the saved email settings.
		$emailSettings = $this->getSettings();

		if (!isset($emailSettings['protocol']))
		{
			throw new Exception(Craft::t('Could not determine how to send the email.  Check your email settings.'));
		}


		// Fire an 'onBeforeSendEmail' event
		$event = new Event($this, array(
			'user' => $user,
			'emailModel' => $emailModel,
			'variables'	 => $variables
		));

		$this->onBeforeSendEmail($event);

		// Is the event giving us the go-ahead?
		if ($event->performAction)
		{
			$email = new \PHPMailer(true);

			// Default the charset to UTF-8
			$email->CharSet = 'UTF-8';

			// Add a reply to (if any).  Make sure it’s set before setting From, because email is dumb.
			if (!empty($emailModel->replyTo))
			{
				$email->addReplyTo($emailModel->replyTo);
			}

			// Set the "from" information.
			$email->setFrom($emailModel->fromEmail, $emailModel->fromName);

			// Check which protocol we need to use.
			switch ($emailSettings['protocol'])
			{
				case EmailerType::Gmail:
				case EmailerType::Smtp:
				{
					$this->_setSmtpSettings($email, $emailSettings);
					break;
				}

				case EmailerType::Pop:
				{
					$pop = new \Pop3();

					if (!isset($emailSettings['host']) || !isset($emailSettings['port']) || !isset($emailSettings['username']) || !isset($emailSettings['password']) ||
						StringHelper::isNullOrEmpty($emailSettings['host']) || StringHelper::isNullOrEmpty($emailSettings['port']) || StringHelper::isNullOrEmpty($emailSettings['username']) || StringHelper::isNullOrEmpty($emailSettings['password'])
					)
					{
						throw new Exception(Craft::t('Host, port, username and password must be configured under your email settings.'));
					}

					if (!isset($emailSettings['timeout']))
					{
						$emailSettings['timeout'] = $this->_defaultEmailTimeout;
					}

					$pop->authorize($emailSettings['host'], $emailSettings['port'], $emailSettings['timeout'], $emailSettings['username'], $emailSettings['password'], craft()->config->get('devMode') ? 1 : 0);

					$this->_setSmtpSettings($email, $emailSettings);
					break;
				}

				case EmailerType::Sendmail:
				{
					$email->isSendmail();
					break;
				}

				case EmailerType::Php:
				{
					$email->isMail();
					break;
				}

				default:
					{
					$email->isMail();
					}
			}

			$testToEmail = craft()->config->get('testToEmailAddress');

			// If they have the test email config var set to a non-empty string use it instead of the supplied email.
			if (is_string($testToEmail) && $testToEmail !== '')
			{
				$email->addAddress($testToEmail, 'Test Email');
			}
			// If they have the test email config var set to a non-empty array use the values instead of the supplied email.
			else if (is_array($testToEmail) && count($testToEmail) > 0)
			{
				foreach ($testToEmail as $testEmail)
				{
					$email->addAddress($testEmail, 'Test Email');
				}
			}
			else
			{
				$email->addAddress($user->email, $user->getFullName());
			}

			// Add any custom headers
			if (!empty($emailModel->customHeaders))
			{
				foreach ($emailModel->customHeaders as $headerName => $headerValue)
				{
					$email->addCustomHeader($headerName, $headerValue);
				}
			}

			// Add any BCC's
			if (!empty($emailModel->bcc))
			{
				foreach ($emailModel->bcc as $bcc)
				{
					if (!empty($bcc['email']))
					{
						$bccEmail = $bcc['email'];

						$bccName = !empty($bcc['name']) ? $bcc['name'] : '';
						$email->addBCC($bccEmail, $bccName);
					}
				}
			}

			// Add any CC's
			if (!empty($emailModel->cc))
			{
				foreach ($emailModel->cc as $cc)
				{
					if (!empty($cc['email']))
					{
						$ccEmail = $cc['email'];

						$ccName = !empty($cc['name']) ? $cc['name'] : '';
						$email->addCC($ccEmail, $ccName);
					}
				}
			}

			// Add a sender header (if any)
			if (!empty($emailModel->sender))
			{
				$email->Sender = $emailModel->sender;
			}

			// Add any string attachments
			if (!empty($emailModel->stringAttachments))
			{
				foreach ($emailModel->stringAttachments as $stringAttachment)
				{
					$email->addStringAttachment($stringAttachment['string'], $stringAttachment['fileName'], $stringAttachment['encoding'], $stringAttachment['type']);
				}
			}

			// Add any normal disc attachments
			if (!empty($emailModel->attachments))
			{
				foreach ($emailModel->attachments as $attachment)
				{
					$email->addAttachment($attachment['path'], $attachment['name'], $attachment['encoding'], $attachment['type']);
				}
			}

			$variables['user'] = $user;

			$email->Subject = craft()->templates->renderString($emailModel->subject, $variables);

			// If they populated an htmlBody, use it.
			if ($emailModel->htmlBody)
			{
				$renderedHtmlBody = craft()->templates->renderString($emailModel->htmlBody, $variables);
				$email->msgHTML($renderedHtmlBody);
				$email->AltBody = craft()->templates->renderString($emailModel->body, $variables);
			}
			else
			{
				// They didn't provide an htmlBody, so markdown the body.
				$renderedHtmlBody = craft()->templates->renderString(StringHelper::parseMarkdown($emailModel->body), $variables);
				$email->msgHTML($renderedHtmlBody);
				$email->AltBody = craft()->templates->renderString($emailModel->body, $variables);
			}

			if (!$email->Send())
			{
				throw new Exception(Craft::t('Email error: {error}', array('error' => $email->ErrorInfo)));
			}

			$success = true;
		}
		else
		{
			$success = false;
		}

		if ($success)
		{
			// Fire an 'onSendEmail' event
			$this->onSendEmail(new Event($this, array(
				'user' => $user,
				'emailModel' => $emailModel,
				'variables'	 => $variables
			)));
		}

		return $success;
	}

	/**
	 * Sets SMTP settings on a given email.
	 *
	 * @param $email
	 * @param $emailSettings
	 *
	 * @throws Exception
	 * @return null
	 */
	private function _setSmtpSettings(&$email, $emailSettings)
	{
		$email->isSMTP();

		if (isset($emailSettings['smtpAuth']) && $emailSettings['smtpAuth'] == 1)
		{
			$email->SMTPAuth = true;

			if ((!isset($emailSettings['username']) && StringHelper::isNullOrEmpty($emailSettings['username'])) || (!isset($emailSettings['password']) && StringHelper::isNullOrEmpty($emailSettings['password'])))
			{
				throw new Exception(Craft::t('Username and password are required.  Check your email settings.'));
			}

			$email->Username = $emailSettings['username'];
			$email->Password = $emailSettings['password'];
		}

		if (isset($emailSettings['smtpKeepAlive']) && $emailSettings['smtpKeepAlive'] == 1)
		{
			$email->SMTPKeepAlive = true;
		}

		$email->SMTPSecure = $emailSettings['smtpSecureTransportType'] != 'none' ? $emailSettings['smtpSecureTransportType'] : null;

		if (!isset($emailSettings['host']))
		{
			throw new Exception(Craft::t('You must specify a host name in your email settings.'));
		}

		if (!isset($emailSettings['port']))
		{
			throw new Exception(Craft::t('You must specify a port in your email settings.'));
		}

		if (!isset($emailSettings['timeout']))
		{
			$emailSettings['timeout'] = $this->_defaultEmailTimeout;
		}

		$email->Host = $emailSettings['host'];
		$email->Port = $emailSettings['port'];
		$email->Timeout = $emailSettings['timeout'];
	}
}
