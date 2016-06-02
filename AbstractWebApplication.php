<?php
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2014 - 2015 LYRASOFT. All rights reserved.
 * @license    GNU Lesser General Public License version 3 or later.
 */

namespace Windwalker\Application;

use Psr\Http\Message\ServerRequestInterface;
use Windwalker\Environment\Browser\Browser;
use Windwalker\Environment\WebEnvironment;
use Windwalker\Http\Request\ServerRequestFactory;
use Windwalker\Http\WebHttpServer;
use Windwalker\Uri\Uri;
use Windwalker\Application\Helper\ApplicationHelper;
use Windwalker\Registry\Registry;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Application for Web HTTP foundation.
 *
 * @property-read  WebEnvironment $environment
 * @property-read  WebHttpServer  $server
 *
 * @since 2.0
 */
abstract class AbstractWebApplication extends AbstractApplication
{
	/**
	 * The application environment object.
	 *
	 * @var    WebEnvironment
	 * @since  2.0
	 */
	protected $environment;

	/**
	 * The system Uri object.
	 *
	 * @var    Uri
	 * @since  2.0
	 */
	protected $uri = null;

	/**
	 * Property request.
	 *
	 * @var  ServerRequestInterface
	 */
	protected $request;

	/**
	 * Property server.
	 *
	 * @var  WebHttpServer
	 */
	protected $server;

	/**
	 * Redirect HTTP codes.
	 *
	 * @var    array
	 * @since  2.1.9
	 */
	protected $redirectCodes = array(
		300 => 'HTTP/2.0 300 Multiple Choices',
		301 => 'HTTP/2.0 301 Moved Permanently',
		302 => 'HTTP/2.0 302 Found',
		303 => 'HTTP/2.0 303 See Other',
		304 => 'Not Modified',
		305 => 'HTTP/2.0 305 Use Proxy',
		306 => 'HTTP/2.0 306 (Unused)',
		307 => 'HTTP/2.0 307 Temporary Redirect',
		308 => 'Permanent Redirect'
	);

	/**
	 * Class constructor.
	 *
	 * @param   Registry        $config       An optional argument to provide dependency injection for the application's
	 *                                        config object.  If the argument is a Registry object that object will become
	 *                                        the application's config object, otherwise a default config object is created.
	 * @param   WebEnvironment          $environment An optional argument to provide dependency injection for the application's
	 *                                        client object.  If the argument is a Web\WebEnvironment object that object will become
	 *                                        the application's client object, otherwise a default client object is created.
	 *
	 * @since   2.0
	 */
	public function __construct(ServerRequestInterface $request = null, Registry $config = null, WebEnvironment $environment = null)
	{
		$this->environment = $environment ? : new WebEnvironment;
		$this->request = $request ? : ServerRequestFactory::createFromGlobals();
		$this->server = WebHttpServer::create(array($this, 'dispatch'), $this->request);

		// Call the constructor as late as possible (it runs `init()`).
		parent::__construct($config);

		// Set the execution datetime and timestamp;
		$this->set('execution.datetime', gmdate('Y-m-d H:i:s'));
		$this->set('execution.timestamp', time());
	}

	/**
	 * Execute the application.
	 *
	 * @return  string
	 *
	 * @since   2.0
	 */
	public function execute()
	{
		$this->prepareExecute();

		// @event onBeforeExecute

		// Perform application routines.
		$this->doExecute();

		// @event onAfterExecute

		$this->postExecute();

		// @event onBeforeRespond

		// @event onAfterRespond
	}

	/**
	 * Method to run the application routines. Most likely you will want to instantiate a controller
	 * and execute it, or perform some sort of task directly.
	 *
	 * @since   2.0
	 */
	protected function doExecute()
	{
		$this->server->listen();
	}

	abstract public function dispatch(Request $request, Response $response, callable $next = null);

	/**
	 * Method to send the application response to the client.  All headers will be sent prior to the main
	 * application output data.
	 *
	 * @param   boolean  $returnBody  Return body or just output it.
	 *
	 * @return  string  The rendered body string.
	 *
	 * @since   2.0
	 */
	public function respond($returnBody = false)
	{
		return $this->output->respond($returnBody);
	}

	/**
	 * Magic method to render output.
	 *
	 * @return  string  Rendered string.
	 *
	 * @since   2.0
	 */
	public function __toString()
	{
		return $this->respond(true);
	}

	/**
	 * Redirect to another URL.
	 *
	 * If the headers have not been sent the redirect will be accomplished using a "301 Moved Permanently"
	 * or "303 See Other" code in the header pointing to the new location. If the headers have already been
	 * sent this will be accomplished using a JavaScript statement.
	 *
	 * @param   string       $url   The URL to redirect to. Can only be http/https URL
	 * @param   boolean|int  $code  True if the page is 301 Permanently Moved, otherwise 303 See Other is assumed.
	 *
	 * @return  void
	 *
	 * @since   2.0
	 */
	public function redirect($url, $code = 303)
	{
		// Check for relative internal links.
		if (preg_match('#^index\.php#', $url))
		{
			$url = $this->get('uri.base.full') . $url;
		}

		// Perform a basic sanity check to make sure we don't have any CRLF garbage.
		$url = preg_split("/[\r\n]/", $url);
		$url = $url[0];

		/*
		 * Here we need to check and see if the URL is relative or absolute.  Essentially, do we need to
		 * prepend the URL with our base URL for a proper redirect.  The rudimentary way we are looking
		 * at this is to simply check whether or not the URL string has a valid scheme or not.
		 */
		if (!preg_match('#^[a-z]+\://#i', $url))
		{
			// Get a URI instance for the requested URI.
			$uri = new Uri($this->get('uri.current'));

			// Get a base URL to prepend from the requested URI.
			$prefix = $uri->toString(array('scheme', 'user', 'pass', 'host', 'port'));

			// We just need the prefix since we have a path relative to the root.
			if ($url[0] == '/')
			{
				$url = $prefix . $url;
			}
			else
				// It's relative to where we are now, so lets add that.
			{
				$parts = explode('/', $uri->toString(array('path')));
				array_pop($parts);
				$path = implode('/', $parts) . '/';
				$url = $prefix . $path . $url;
			}
		}

		// If the headers have already been sent we need to send the redirect statement via JavaScript.
		if ($this->output->checkHeadersSent())
		{
			echo "<script>document.location.href='$url';</script>\n";
		}
		else
		{
			// We have to use a JavaScript redirect here because MSIE doesn't play nice with utf-8 URLs.
			if (($this->environment->browser->getEngine() == Browser::TRIDENT) && !ApplicationHelper::isAscii($url))
			{
				$html = '<html><head>';
				$html .= '<meta http-equiv="content-type" content="text/html; charset=' . $this->output->getCharSet() . '" />';
				$html .= '<script>document.location.href=\'' . $url . '\';</script>';
				$html .= '</head><body></body></html>';

				echo $html;
			}
			else
			{
				// All other cases use the more efficient HTTP header for redirection.
				if (!array_key_exists((int) $code, $this->redirectCodes))
				{
					$code = $code ? 301 : 303;
				}

				$this->output->header($this->redirectCodes[$code]);
				$this->output->header('Location: ' . $url);
				$this->output->header('Content-Type: text/html; charset=' . $this->output->getCharSet());
			}
		}

		// Close the application after the redirect.
		$this->close();
	}

	/**
	 * Method to get property Environment
	 *
	 * @return  \Windwalker\Environment\WebEnvironment
	 *
	 * @since   2.0
	 */
	public function getEnvironment()
	{
		return $this->environment;
	}

	/**
	 * Method to set property environment
	 *
	 * @param   \Windwalker\Environment\WebEnvironment $environment
	 *
	 * @return  static  Return self to support chaining.
	 *
	 * @since   2.0
	 */
	public function setEnvironment($environment)
	{
		$this->environment = $environment;

		return $this;
	}

	/**
	 * is utilized for reading data from inaccessible members.
	 *
	 * @param   $name  string
	 *
	 * @return  mixed
	 */
	public function __get($name)
	{
		$allowNames = array(
			'environment',
			'server'
		);

		if (in_array($name, $allowNames))
		{
			return $this->$name;
		}

		return parent::__get($name);
	}
}
