<?php

namespace SISIS\ILS\Driver;

class SISISNCIPFactory implements \Laminas\ServiceManager\Factory\FactoryInterface
{
    /**
     * Create an object
     *
     * @param ContainerInterface $container     Service manager
     * @param string             $requestedName Service being created
     * @param null|array         $options       Extra options (optional)
     *
     * @return object
     *
     * @throws ServiceNotFoundException if unable to resolve the service.
     * @throws ServiceNotCreatedException if an exception is raised when
     * creating a service.
     * @throws ContainerException&\Throwable if any other error occurs
     */
    public function __invoke(\Psr\Container\ContainerInterface $container, $requestedName, array $options = null)
    {
        return new $requestedName($container->get(\VuFind\Record\Loader::class));
    }
}
