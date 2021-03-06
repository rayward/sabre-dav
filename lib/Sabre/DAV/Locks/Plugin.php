<?php

namespace Sabre\DAV\Locks;

use
    Sabre\DAV,
    Sabre\HTTP\RequestInterface,
    Sabre\HTTP\ResponseInterface;

/**
 * Locking plugin
 *
 * This plugin provides locking support to a WebDAV server.
 * The easiest way to get started, is by hooking it up as such:
 *
 * $lockBackend = new Sabre\DAV\Locks\Backend\File('./mylockdb');
 * $lockPlugin = new Sabre\DAV\Locks\Plugin($lockBackend);
 * $server->addPlugin($lockPlugin);
 *
 * @copyright Copyright (C) 2007-2013 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Plugin extends DAV\ServerPlugin {

    /**
     * locksBackend
     *
     * @var Backend\Backend\Interface
     */
    protected $locksBackend;

    /**
     * server
     *
     * @var Sabre\DAV\Server
     */
    protected $server;

    /**
     * __construct
     *
     * @param Backend\BackendInterface $locksBackend
     */
    public function __construct(Backend\BackendInterface $locksBackend = null) {

        $this->locksBackend = $locksBackend;

    }

    /**
     * Initializes the plugin
     *
     * This method is automatically called by the Server class after addPlugin.
     *
     * @param DAV\Server $server
     * @return void
     */
    public function initialize(DAV\Server $server) {

        $this->server = $server;
        $server->on('method:LOCK',   [$this, 'httpLock']);
        $server->on('method:UNLOCK', [$this, 'httpUnlock']);
        $server->on('afterGetProperties',array($this,'afterGetProperties'));
        $server->on('validateTokens', array($this, 'validateTokens'));

    }

    /**
     * Returns a plugin name.
     *
     * Using this name other plugins will be able to access other plugins
     * using Sabre\DAV\Server::getPlugin
     *
     * @return string
     */
    public function getPluginName() {

        return 'locks';

    }

    /**
     * This method is called after most properties have been found
     * it allows us to add in any Lock-related properties
     *
     * @param string $path
     * @param array $newProperties
     * @return bool
     */
    public function afterGetProperties($path, &$newProperties) {

        foreach($newProperties[404] as $propName=>$discard) {

            switch($propName) {

                case '{DAV:}supportedlock' :
                    $val = false;
                    if ($this->locksBackend) $val = true;
                    $newProperties[200][$propName] = new DAV\Property\SupportedLock($val);
                    unset($newProperties[404][$propName]);
                    break;

                case '{DAV:}lockdiscovery' :
                    $newProperties[200][$propName] = new DAV\Property\LockDiscovery($this->getLocks($path));
                    unset($newProperties[404][$propName]);
                    break;

            }


        }
        return true;

    }

    /**
     * Use this method to tell the server this plugin defines additional
     * HTTP methods.
     *
     * This method is passed a uri. It should only return HTTP methods that are
     * available for the specified uri.
     *
     * @param string $uri
     * @return array
     */
    public function getHTTPMethods($uri) {

        if ($this->locksBackend)
            return array('LOCK','UNLOCK');

        return array();

    }

    /**
     * Returns a list of features for the HTTP OPTIONS Dav: header.
     *
     * In this case this is only the number 2. The 2 in the Dav: header
     * indicates the server supports locks.
     *
     * @return array
     */
    public function getFeatures() {

        return array(2);

    }

    /**
     * Returns all lock information on a particular uri
     *
     * This function should return an array with Sabre\DAV\Locks\LockInfo objects. If there are no locks on a file, return an empty array.
     *
     * Additionally there is also the possibility of locks on parent nodes, so we'll need to traverse every part of the tree
     * If the $returnChildLocks argument is set to true, we'll also traverse all the children of the object
     * for any possible locks and return those as well.
     *
     * @param string $uri
     * @param bool $returnChildLocks
     * @return array
     */
    public function getLocks($uri, $returnChildLocks = false) {

        $lockList = array();

        if ($this->locksBackend)
            $lockList = array_merge($lockList,$this->locksBackend->getLocks($uri, $returnChildLocks));

        return $lockList;

    }

    /**
     * Locks an uri
     *
     * The WebDAV lock request can be operated to either create a new lock on a file, or to refresh an existing lock
     * If a new lock is created, a full XML body should be supplied, containing information about the lock such as the type
     * of lock (shared or exclusive) and the owner of the lock
     *
     * If a lock is to be refreshed, no body should be supplied and there should be a valid If header containing the lock
     *
     * Additionally, a lock can be requested for a non-existent file. In these case we're obligated to create an empty file as per RFC4918:S7.3
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool
     */
    public function httpLock(RequestInterface $request, ResponseInterface $response) {

        $lastLock = null;

        $uri = $request->getPath();

        $existingLocks = $this->getLocks($uri);

        if ($body = $request->getBody($asStrign = true)) {
            // This is a new lock request

            $existingLock = null;
            // Checking if there's already non-shared locks on the uri.
            foreach($existingLocks as $existingLock) {
                if ($existingLock->scope === LockInfo::EXCLUSIVE) {
                    throw new DAV\Exception\ConflictingLock($existingLock);
                }
            }

            $lockInfo = $this->parseLockRequest($body);
            $lockInfo->depth = $this->server->getHTTPDepth();
            $lockInfo->uri = $uri;
            if($existingLock && $lockInfo->scope != LockInfo::SHARED)
                throw new DAV\Exception\ConflictingLock($existingLock);

        } else {

            // Gonna check if this was a lock refresh.
            $existingLocks = $this->getLocks($uri);
            $conditions = $this->server->getIfConditions();
            $found = null;


            foreach($existingLocks as $existingLock) {
                foreach($conditions as $condition) {
                    foreach($condition['tokens'] as $token) {
                        if ($token['token'] === 'opaquelocktoken:' . $existingLock->token) {
                            $found = $existingLock;
                            break 3;
                        }
                    }
                }
            }

            // If none were found, this request is in error.
            if (is_null($found)) {
                if ($existingLocks) {
                    throw new DAV\Exception\Locked(reset($existingLocks));
                } else {
                    throw new DAV\Exception\BadRequest('An xml body is required for lock requests');
                }

            }

            // This must have been a lock refresh
            $lockInfo = $found;

            // The resource could have been locked through another uri.
            if ($uri!=$lockInfo->uri) $uri = $lockInfo->uri;

        }

        if ($timeout = $this->getTimeoutHeader()) $lockInfo->timeout = $timeout;

        $newFile = false;

        // If we got this far.. we should go check if this node actually exists. If this is not the case, we need to create it first
        try {
            $this->server->tree->getNodeForPath($uri);

            // We need to call the beforeWriteContent event for RFC3744
            // Edit: looks like this is not used, and causing problems now.
            //
            // See Issue 222
            // $this->server->emit('beforeWriteContent',array($uri));

        } catch (DAV\Exception\NotFound $e) {

            // It didn't, lets create it
            $this->server->createFile($uri,fopen('php://memory','r'));
            $newFile = true;

        }

        $this->lockNode($uri,$lockInfo);

        $response->setHeader('Content-Type','application/xml; charset=utf-8');
        $response->setHeader('Lock-Token','<opaquelocktoken:' . $lockInfo->token . '>');
        $response->setStatus($newFile?201:200);
        $response->setBody($this->generateLockResponse($lockInfo));

        // Returning false will interupt the event chain and mark this method
        // as 'handled'.
        return false;

    }

    /**
     * Unlocks a uri
     *
     * This WebDAV method allows you to remove a lock from a node. The client should provide a valid locktoken through the Lock-token http header
     * The server should return 204 (No content) on success
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return void
     */
    public function httpUnlock(RequestInterface $request, ResponseInterface $response) {

        $lockToken = $request->getHeader('Lock-Token');

        // If the locktoken header is not supplied, we need to throw a bad request exception
        if (!$lockToken) throw new DAV\Exception\BadRequest('No lock token was supplied');

        $path = $request->getPath();
        $locks = $this->getLocks($path);

        // Windows sometimes forgets to include < and > in the Lock-Token
        // header
        if ($lockToken[0]!=='<') $lockToken = '<' . $lockToken . '>';

        foreach($locks as $lock) {

            if ('<opaquelocktoken:' . $lock->token . '>' == $lockToken) {

                $this->unlockNode($path,$lock);
                $response->setHeader('Content-Length','0');
                $response->setStatus(204);

                // Returning false will break the method chain, and mark the
                // method as 'handled'.
                return false;

            }

        }

        // If we got here, it means the locktoken was invalid
        throw new DAV\Exception\LockTokenMatchesRequestUri();

    }

    /**
     * Locks a uri
     *
     * All the locking information is supplied in the lockInfo object. The object has a suggested timeout, but this can be safely ignored
     * It is important that if the existing timeout is ignored, the property is overwritten, as this needs to be sent back to the client
     *
     * @param string $uri
     * @param LockInfo $lockInfo
     * @return bool
     */
    public function lockNode($uri,LockInfo $lockInfo) {

        if (!$this->server->emit('beforeLock', [$uri,$lockInfo])) return;

        if ($this->locksBackend) return $this->locksBackend->lock($uri,$lockInfo);
        throw new DAV\Exception\MethodNotAllowed('Locking support is not enabled for this resource. No Locking backend was found so if you didn\'t expect this error, please check your configuration.');

    }

    /**
     * Unlocks a uri
     *
     * This method removes a lock from a uri. It is assumed all the supplied information is correct and verified
     *
     * @param string $uri
     * @param LockInfo $lockInfo
     * @return bool
     */
    public function unlockNode($uri, LockInfo $lockInfo) {

        if (!$this->server->emit('beforeUnlock', [$uri,$lockInfo])) return;
        if ($this->locksBackend) return $this->locksBackend->unlock($uri,$lockInfo);

    }


    /**
     * Returns the contents of the HTTP Timeout header.
     *
     * The method formats the header into an integer.
     *
     * @return int
     */
    public function getTimeoutHeader() {

        $header = $this->server->httpRequest->getHeader('Timeout');

        if ($header) {

            if (stripos($header,'second-')===0) $header = (int)(substr($header,7));
            else if (strtolower($header)=='infinite') $header = LockInfo::TIMEOUT_INFINITE;
            else throw new DAV\Exception\BadRequest('Invalid HTTP timeout header');

        } else {

            $header = 0;

        }

        return $header;

    }

    /**
     * Generates the response for successful LOCK requests
     *
     * @param LockInfo $lockInfo
     * @return string
     */
    protected function generateLockResponse(LockInfo $lockInfo) {

        $dom = new \DOMDocument('1.0','utf-8');
        $dom->formatOutput = true;

        $prop = $dom->createElementNS('DAV:','d:prop');
        $dom->appendChild($prop);

        $lockDiscovery = $dom->createElementNS('DAV:','d:lockdiscovery');
        $prop->appendChild($lockDiscovery);

        $lockObj = new DAV\Property\LockDiscovery(array($lockInfo),true);
        $lockObj->serialize($this->server,$lockDiscovery);

        return $dom->saveXML();

    }

    /**
     * The validateTokens event is triggered before every request.
     *
     * It's a moment where this plugin can check all the supplied lock tokens
     * in the If: header, and check if they are valid.
     *
     * In addition, it will also ensure that it checks any missing lokens that
     * must be present in the request, and reject requests without the proper
     * tokens.
     *
     * @param mixed $conditions
     * @return void
     */
    public function validateTokens( &$conditions ) {

        // First we need to gather a list of locks that must be satisfied.
        $mustLocks = [];
        $method = $this->server->httpRequest->getMethod();

        // Methods not in that list are operations that doesn't alter any
        // resources, and we don't need to check the lock-states for.
        switch($method) {

            case 'DELETE' :
                $mustLocks = array_merge($mustLocks, $this->getLocks(
                    $this->server->getRequestUri(),
                    true
                ));
                break;
            case 'MKCOL' :
            case 'MKCALENDAR' :
            case 'PROPPATCH' :
            case 'PUT' :
            case 'PATCH' :
                $mustLocks = array_merge($mustLocks, $this->getLocks(
                    $this->server->getRequestUri(),
                    false
                ));
                break;
            case 'MOVE' :
                $mustLocks = array_merge($mustLocks, $this->getLocks(
                    $this->server->getRequestUri(),
                    true
                ));
                $mustLocks = array_merge($mustLocks, $this->getLocks(
                    $this->server->calculateUri($this->server->httpRequest->getHeader('Destination')),
                    false
                ));
                break;
            case 'COPY' :
                $mustLocks = array_merge($mustLocks, $this->getLocks(
                    $this->server->calculateUri($this->server->httpRequest->getHeader('Destination')),
                    false
                ));
                break;
        }

        // It's possible that there's identical locks, because of shared
        // parents. We're removing the duplicates here.
        $tmp = [];
        foreach($mustLocks as $lock) $tmp[$lock->token] = $lock;
        $mustLocks = array_values($tmp);

        foreach($conditions as $kk=>$condition) {

            foreach($condition['tokens'] as $ii=>$token) {

                // Lock tokens always start with opaquelocktoken:
                if (substr($token['token'], 0, 16) !== 'opaquelocktoken:') {
                    continue;
                }

                $checkToken = substr($token['token'],16);
                // Looping through our list with locks.
                foreach($mustLocks as $jj => $mustLock) {

                    if ($mustLock->token == $checkToken) {

                        // We have a match!
                        // Removing this one from mustlocks
                        unset($mustLocks[$jj]);

                        // Marking the condition as valid.
                        $conditions[$kk]['tokens'][$ii]['validToken'] = true;

                        // Advancing to the next token
                        continue 2;

                    }

                    // If we got here, it means that there was a
                    // lock-token, but it was not in 'mustLocks'.
                    //
                    // This is an edge-case, as it could mean that token
                    // was specified with a url that was not 'required' to
                    // check. So we're doing one extra lookup to make sure
                    // we really don't know this token.
                    //
                    // This also gets triggered when the user specified a
                    // lock-token that was expired.
                    $oddLocks = $this->getLocks($condition['uri']);
                    foreach($oddLocks as $oddLock) {

                        if ($oddLock->token === $checkToken) {

                            // We have a hit!
                            $conditions[$kk]['tokens'][$ii]['validToken'] = true;
                            continue 2;

                        }
                    }

                    // If we get all the way here, the lock-token was
                    // really unknown.

                }


            }

        }

        // If there's any locks left in the 'mustLocks' array, it means that
        // the resource was locked and we must block it.
        if ($mustLocks) {

            throw new DAV\Exception\Locked(reset($mustLocks));

        }

    }

    /**
     * Parses a webdav lock xml body, and returns a new Sabre\DAV\Locks\LockInfo object
     *
     * @param string $body
     * @return LockInfo
     */
    protected function parseLockRequest($body) {

        $xml = simplexml_load_string(
            DAV\XMLUtil::convertDAVNamespace($body),
            null,
            LIBXML_NOWARNING);
        $xml->registerXPathNamespace('d','urn:DAV');
        $lockInfo = new LockInfo();

        $children = $xml->children("urn:DAV");
        $lockInfo->owner = (string)$children->owner;

        $lockInfo->token = DAV\UUIDUtil::getUUID();
        $lockInfo->scope = count($xml->xpath('d:lockscope/d:exclusive'))>0 ? LockInfo::EXCLUSIVE : LockInfo::SHARED;

        return $lockInfo;

    }


}
