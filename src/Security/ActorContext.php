<?php

namespace SmartCloud\AgentComposer\Security;

final class ActorContext {
	public function __construct(
		private readonly string $principal_id,
		private readonly string $issuer,
		private readonly string $subject,
		private readonly string $email,
		private readonly array $groups,
		private readonly string $client_id,
		private readonly array $scopes,
		private readonly string $role,
		private readonly string $mode,
		private readonly string $source
	) {}

	public function principal_id(): string { return $this->principal_id; }
	public function issuer(): string { return $this->issuer; }
	public function subject(): string { return $this->subject; }
	public function email(): string { return $this->email; }
	public function groups(): array { return $this->groups; }
	public function client_id(): string { return $this->client_id; }
	public function scopes(): array { return $this->scopes; }
	public function role(): string { return $this->role; }
	public function mode(): string { return $this->mode; }
	public function source(): string { return $this->source; }

	public function audit_context(): array {
		return array(
			'principal_id' => $this->principal_id,
			'issuer'       => $this->issuer,
			'email'        => $this->email,
			'groups'       => $this->groups,
			'client_id'    => $this->client_id,
			'scopes'       => $this->scopes,
			'effective_role' => $this->role,
			'security_mode'  => $this->mode,
			'identity_source' => $this->source,
		);
	}

	public function public_summary(): array {
		return array(
			'principal_id'  => $this->principal_id,
			'email'         => $this->email,
			'groups'        => $this->groups,
			'client_id'     => $this->client_id,
			'scopes'        => $this->scopes,
			'effective_role'=> $this->role,
			'security_mode' => $this->mode,
			'identity_source' => $this->source,
		);
	}
}
