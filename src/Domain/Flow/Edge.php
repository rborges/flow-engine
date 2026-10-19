<?php

namespace FlowEngine\Domain\Flow;

/**
 * Representa uma dependência entre nodes.
 * 
 * Imutável. Value Object.
 * 
 * Tipos de edges:
 * - method_call: Chamada de método ($this->method())
 * - property_access: Acesso a propriedade ($this->property)
 * - static_call: Chamada estática (Class::method())
 * - static_property: Propriedade estática (Class::$property)
 * - function_call: Chamada de função livre (helper())
 * - include: Inclusão de arquivo (require 'x.php')
 * - direct_instantiation: Instanciação direta (new Class())
 * - inherits / trait_usage / interface_implementation: relações estruturais
 * - http_call, model_relationship: relações de framework
 */
final class Edge
{
    public function __construct(
        private string $fromNodeId,
        private string $toNodeId,
        private string $method,
        private string $type = 'method_call' // Default: backward compatible
    ) {
    }
    
    /**
     * 
     * @api
     */
    public function from(): string
    {
        return $this->fromNodeId;
    }
    
    /**
     * 
     * @api
     */
    public function to(): string
    {
        return $this->toNodeId;
    }
    
    public function method(): string
    {
        return $this->method;
    }
    
    /**
     * Tipo de dependência.
     * 
     * @api
     * @return string 'method_call'|'property_access'|'static_call'|'static_property'|'trait_usage'|'interface_implementation'
     */
    public function type(): string
    {
        return $this->type;
    }
    
    /**
     * Verifica se é chamada de método.
     * 
     * @api
     */
    public function isMethodCall(): bool
    {
        return $this->type === 'method_call';
    }
    
    /**
     * Verifica se é acesso a propriedade.
     * 
     * @api
     */
    public function isPropertyAccess(): bool
    {
        return $this->type === 'property_access';
    }
    
    /**
     * Verifica se é chamada estática.
     * @api
     */
    public function isStaticCall(): bool
    {
        return $this->type === 'static_call';
    }
    
    /**
     * Verifica se é propriedade estática.
     */
    public function isStaticProperty(): bool
    {
        return $this->type === 'static_property';
    }
    
    /**
     * Verifica se é uso de trait.
     */
    public function isTraitUsage(): bool
    {
        return $this->type === 'trait_usage';
    }
    
    /**
     * Verifica se é implementação de interface.
     */
    public function isInterfaceImplementation(): bool
    {
        return $this->type === 'interface_implementation';
    }
}