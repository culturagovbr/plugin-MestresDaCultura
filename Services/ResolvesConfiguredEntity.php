<?php

namespace MDC\Services;

use MapasCulturais\App;

/**
 * A entidade configurada por id no ambiente (a oportunidade do programa, os selos), guardada entre as chamadas
 * enquanto o id é o mesmo e o Doctrine a gerencia.
 */
trait ResolvesConfiguredEntity
{
    private ?int $configuredFor = null;

    private ?object $configured = null;

    // O id da CONFIG_KEY de quem usa o trait, sem buscar a entidade nem registrar no log.
    function configuredId(): ?int
    {
        return ((int) (App::i()->config[self::CONFIG_KEY] ?? 0)) ?: null;
    }

    /**
     * @param string $missing o aviso no log quando não há id configurado
     * @param string $notFound o aviso quando o id não existe, com `%d` no lugar do id
     */
    protected function resolveConfigured(string $key, string $repo, string $missing, string $notFound): ?object
    {
        $app = App::i();
        $id = (int) ($app->config[$key] ?? 0);

        if ($this->configuredFor === $id && (!$this->configured || $app->em->contains($this->configured))) {
            return $this->configured;
        }

        $this->configuredFor = $id;
        $this->configured = null;

        if (!$id) {
            $app->log->error($missing);

            return null;
        }

        $entity = $app->repo($repo)->find($id);

        if (!$entity) {
            $app->log->error(sprintf($notFound, $id));

            return null;
        }

        return $this->configured = $entity;
    }
}
