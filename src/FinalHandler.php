<?php
namespace Phly\Conduit;

use Exception;
use Zend\Escaper\Escaper;

/**
 * Handle incomplete requests
 */
class FinalHandler
{
    /**
     * @var array
     */
    private $options;

    /**
     * @var Http\Request
     */
    private $request;

    /**
     * @var Http\Response
     */
    private $response;

    /**
     * @param Http\Request $request
     * @param Http\Response $response
     */
    public function __construct(Http\Request $request, Http\Response $response, array $options = [])
    {
        $this->request  = $request;
        $this->response = $response;
        $this->options  = $options;
    }

    /**
     * Handle incomplete requests
     *
     * This handler should only ever be invoked if Next exhausts its stack.
     * When that happens, we determine if an $err is present, and, if so,
     * create a 500 status with error details.
     *
     * Otherwise, a 404 status is created.
     *
     * @param null|mixed $err
     * @return Http\Response
     */
    public function __invoke($err = null)
    {
        if ($err) {
            return $this->handleError($err);
        }

        return $this->create404();
    }

    /**
     * Handle an error condition
     *
     * Use the $error to create details for the response.
     *
     * @param mixed $error
     * @return Http\Response
     */
    private function handleError($error)
    {
        $response = $this->response->setStatus(
            $this->getStatusCode($error, $this->response)
        );

        $message = $response->getReasonPhrase() ?: 'Unknown Error';
        if (! isset($this->options['env'])
            || $this->options['env'] !== 'production'
        ) {
            $message = $this->createDevelopmentErrorMessage($error);
        }

        $this->triggerError($error, $this->request, $response);

        $response->end($message);
        return $response;
    }

    /**
     * Create a 404 status in the response
     *
     * @return Http\Response
     */
    private function create404()
    {
        $response = $this->response->setStatus(404);

        $originalRequest = $this->request->getOriginalRequest();
        $url             = $originalRequest->getAbsoluteUri();
        $escaper         = new Escaper();
        $message         = sprintf(
            "Cannot %s %s\n",
            $escaper->escapeHtml($this->request->getMethod()),
            $escaper->escapeHtml((string) $url)
        );
        $response->end($message);
        return $response;
    }

    /**
     * Determine status code
     *
     * If the error is an exception with a code between 400 and 599, returns
     * the exception code.
     *
     * Otherwise, retrieves the code from the response; if not present, or
     * less than 400 or greater than 599, returns 500; otherwise, returns it.
     *
     * @param mixed $error
     * @param Http\Response $response
     * @return int
     */
    private function getStatusCode($error, Http\Response $response)
    {
        if ($error instanceof Exception
            && ($error->getCode() >= 400 && $error->getCode() < 600)
        ) {
            return $error->getCode();
        }

        $status = $response->getStatusCode();
        if (! $status || $status < 400 || $status >= 600) {
            $status = 500;
        }
        return $status;
    }

    private function createDevelopmentErrorMessage($error)
    {
        if ($error instanceof Exception) {
            $message  = $error->getMessage() . "\n";
            $message .= $error->getTraceAsString();
        } elseif (is_object($error) && ! method_exists($error, '__toString')) {
            $message = sprintf('Error of type "%s" occurred', get_class($error));
        } else {
            $message = (string) $error;
        }

        $escaper = new Escaper();
        return $escaper->escapeHtml($message);
    }

    /**
     * Trigger the error listener, if present
     *
     * @param mixed $error
     * @param Http\Request $request
     * @param Http\Response $response
     */
    private function triggerError($error, Http\Request $request, Http\Response $response)
    {
        if (! isset($this->options['onerror'])
            || ! is_callable($this->options['onerror'])
        ) {
            return;
        }

        $onError = $this->options['onerror'];
        $onError($error, $request, $response);
    }
}
