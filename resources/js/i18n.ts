import i18n from "i18next";
import { initReactI18next, useTranslation } from "react-i18next";
import { router } from "@inertiajs/react";

export const localeCodes = ["en", "pt_BR", "es"] as const;
export type LocaleCode = (typeof localeCodes)[number];

export const resources = {
  en: {
    translation: {
      "language.change": "Change language",
      "language.updating": "Changing language",
      "common.back": "Back to projects",
      "common.create": "Create",
      "common.status": "Status",
      "welcome.title": "Migrate your IPAM safely.",
      "welcome.body":
        "Discover, map, review and apply a phpIPAM → NetBox migration with an auditable plan.",
      "welcome.setup": "Set up installation",
      "welcome.login": "Sign in",
      "setup.title": "Claim installation",
      "setup.help": "Use the token shown by",
      "setup.token": "Token",
      "setup.name": "Name",
      "setup.email": "Email",
      "setup.password": "Password",
      "setup.password_help":
        "14–128 characters, with uppercase, lowercase, number and symbol.",
      "setup.confirm": "Confirm password",
      "setup.submit": "Create owner",
      "login.title": "Sign in to IpamFerry",
      "login.submit": "Sign in",
      "dashboard.title": "Migration projects",
      "dashboard.new": "New project",
      "dashboard.empty": "Create a project to start discovery.",
      "dashboard.plans": "{{count}} plan(s) generated",
      "projects.new": "New project",
      "projects.name": "Name",
      "projects.source": "Source",
      "projects.existing": "Existing projects",
      "projects.api": "phpIPAM API",
      "projects.dump": "SQL mysqldump",
      "projects.locale": "Artifact language",
      "show.import": "1. Import mysqldump",
      "show.sql_safe": "SQL is parsed and never executed.",
      "show.dump_file": "SQL dump",
      "show.read_dump": "Read dump",
      "show.discover": "1. Discover APIs",
      "show.tokens_safe": "Tokens are held only in browser and request memory.",
      "show.run_discovery": "Run discovery",
      "show.plan_apply": "4. Review, approve and apply",
      "show.generate_plan": "Generate plan",
      "show.actions_conflicts": "{{actions}} actions, {{conflicts}} conflicts",
      "show.mapping_warning":
        "{{type}} requires mapping review before export to NetBox.",
      "show.confirm_apply": "I confirm applying this exact approved plan",
      "show.apply": "Apply through API",
      "show.bundle": "Download audit bundle",
      "show.netbox_url": "NetBox URL",
      "show.netbox_token": "NetBox token",
      "show.phpipam_url": "phpIPAM URL",
      "show.phpipam_app": "App ID",
      "show.phpipam_token": "phpIPAM token",
      "show.inventory": "2. Canonical inventory",
      "show.inventory_empty":
        "Run discovery to build the sanitized canonical inventory.",
      "show.mapping": "3. Mapping policy",
      "show.mapping_help":
        "Edit the versioned, non-executable mapping policy in Mapping Studio.",
      "show.artifact_locale_help":
        "Used only for reports, preservation files, and the audit bundle. It does not change the interface language.",
      "show.save_artifact_locale": "Update artifact language",
      "show.mapping_json": "Mapping JSON",
      "show.save_mapping": "Save mapping",
      "show.plan_help":
        "Planning is read-only. Conflicts prevent approval and apply.",
      "show.planning": "Planning…",
      "show.conflicts": "Blocking conflicts",
      "show.actions": "Planned actions",
      "show.operation": "Operation",
      "show.source": "Source",
      "show.target": "NetBox target",
      "show.identity": "Natural key",
      "show.confirm_approve":
        "I reviewed the diff and approve this exact fingerprint",
      "show.approve": "Approve plan",
      "show.applying": "Applying batch…",
      "show.resume": "Resume apply",
      "show.execution": "Execution #{{id}}",
      "show.progress": "{{completed}} of {{total}} actions completed",
      "show.verify": "5. Verify target state",
      "show.verifying": "Verifying…",
      "show.run_verify": "Run verification",
      "mapping.open": "Open Mapping Studio",
      "mapping.title": "Mapping Studio",
      "mapping.back": "Back to project",
      "mapping.revision": "Revision {{revision}}",
      "mapping.undo": "Undo",
      "mapping.redo": "Redo",
      "mapping.save": "Save mapping",
      "mapping.saving": "Saving…",
      "mapping.saved": "Mapping saved.",
      "mapping.save_failed": "The mapping could not be saved.",
      "mapping.unsaved_confirm": "Discard unsaved mapping changes?",
      "mapping.locked":
        "Mapping is locked while a migration execution is active.",
      "mapping.read_only":
        "Your reader role can inspect this mapping but cannot change it.",
      "mapping.validation_errors": "Mapping validation errors",
      "mapping.sections": "Mapping Studio sections",
      "mapping.tab.overview": "Overview",
      "mapping.tab.objects": "Objects",
      "mapping.tab.references": "References",
      "mapping.tab.fields": "Fields",
      "mapping.tab.status": "Status / updates",
      "mapping.tab.relations": "Relations",
      "mapping.tab.preview": "Preview",
      "mapping.tab.json": "JSON Expert",
      "mapping.source_objects": "Source objects",
      "mapping.target_objects": "NetBox objects",
      "mapping.object_types": "object types",
      "mapping.suggestions": "Suggestions",
      "mapping.review_required": "review is always required",
      "mapping.v1_detected": "Mapping schema v1 detected",
      "mapping.v1_help":
        "Review the proposed conversion. It is only persisted after an explicit save.",
      "mapping.upgrade": "Convert to v2",
      "mapping.deterministic_suggestions": "Deterministic suggestions",
      "mapping.suggestions_help":
        "Suggestions use names, slugs, types and natural keys. Nothing is accepted automatically.",
      "mapping.accept_all": "Accept all",
      "mapping.no_suggestions": "No pending suggestions.",
      "mapping.reason.matching_object_type": "matching object type",
      "mapping.reason.matching_field_name": "matching field name",
      "mapping.reason.matching_type_and_natural_key":
        "matching type and natural key",
      "mapping.reason.matching_slug": "name normalized to a natural-key slug",
      "mapping.accept": "Accept suggestion",
      "mapping.object_policies": "Object policies",
      "mapping.object_help":
        "Choose what is migrated, preserved in the audit bundle or explicitly ignored.",
      "mapping.records": "records",
      "mapping.fields": "fields",
      "mapping.policy.migrate": "Migrate",
      "mapping.policy.preserve": "Preserve",
      "mapping.policy.ignore": "Ignore",
      "mapping.preservation_handling": "Preservation handling",
      "mapping.preservation.report": "Include in report",
      "mapping.preservation.note": "Map to note",
      "mapping.preservation.custom_field": "Map to custom field",
      "mapping.preservation.discard": "Explicitly discard",
      "mapping.target_type": "NetBox target type",
      "mapping.field_rules": "Field rules",
      "mapping.field_help":
        "Only declarative and non-executable transformations are supported.",
      "mapping.source_field": "Source field",
      "mapping.target_field": "Target field",
      "mapping.action.copy": "Copy",
      "mapping.action.ignore": "Ignore",
      "mapping.action.fixed": "Fixed value",
      "mapping.action.concat": "Concatenate",
      "mapping.action.normalize": "Normalize",
      "mapping.action.lookup": "Lookup table",
      "mapping.remove": "Remove rule",
      "mapping.no_field_rules": "No field rules yet.",
      "mapping.target_regular": "Object field",
      "mapping.target_custom": "Custom field",
      "mapping.fixed_value": "Fixed value",
      "mapping.concat_fields": "Fields separated by commas",
      "mapping.separator": "Separator",
      "mapping.lookup_table": "Lookup conversion table",
      "mapping.reference_rules": "Reference rules",
      "mapping.reference_help":
        "Resolve references by natural key, never by a NetBox numeric ID.",
      "mapping.no_reference_rules": "No reference rules yet.",
      "mapping.relation_rules": "Relation rules",
      "mapping.relation_help":
        "Enable only relationships whose prerequisites can be proved.",
      "mapping.no_relation_rules": "No relation rules yet.",
      "mapping.source_type": "Source type",
      "mapping.relation": "Relation",
      "mapping.enabled": "Enabled",
      "mapping.relation.location_classification": "Location classification",
      "mapping.relation.device_defaults": "Device prerequisites",
      "mapping.relation.customer_contacts": "Customer contacts",
      "mapping.relation.asn_defaults": "ASN / RIR",
      "mapping.relation.circuit_terminations": "Circuit terminations",
      "mapping.relation.primary_ip": "Primary IP",
      "mapping.relation.nat_1to1": "Static NAT 1:1",
      "mapping.location_classification": "Classify phpIPAM locations",
      "mapping.location_help":
        "Every location must become a Site or a Location subordinate to an explicitly selected Site.",
      "mapping.choose": "Choose…",
      "mapping.as_site": "Site",
      "mapping.as_location": "Location",
      "mapping.choose_site": "Choose parent Site…",
      "mapping.no_locations": "No locations were discovered.",
      "mapping.fallback_site_name": "Fallback Site name",
      "mapping.fallback_help": "Used only for devices with no source location.",
      "mapping.device_categories": "Device category requirements",
      "mapping.device_help":
        "Each category requires a Role, Manufacturer, physical Device Type and Interface Type.",
      "mapping.device_exceptions": "Individual device exceptions",
      "mapping.device_exceptions_help":
        "Override the category role, hardware, or interface type only for devices that need an exception.",
      "mapping.device_role": "NetBox device role",
      "mapping.override_hardware": "Override hardware",
      "mapping.override_manufacturer": "Override manufacturer",
      "mapping.override_device_type": "Override physical model",
      "mapping.override_interface_type": "Override interface type",
      "mapping.manufacturer": "Manufacturer",
      "mapping.device_type": "Physical device model",
      "mapping.interface_type": "NetBox interface type",
      "mapping.no_device_categories":
        "No phpIPAM device categories were discovered.",
      "mapping.nat_confirmation": "Confirm static 1:1 NAT",
      "mapping.nat_help":
        "Confirm only IP-to-IP pairs without ports. PAT remains preserved.",
      "mapping.advanced_relations": "Advanced relation JSON fields",
      "mapping.contact_role": "Contact Role",
      "mapping.contact_help":
        "Contacts are created and assigned to their Tenant only after this role is approved.",
      "mapping.contact_role_name": "Contact Role name",
      "mapping.rir_defaults": "RIR for discovered ASNs",
      "mapping.rir_help": "NetBox requires every ASN to reference an RIR.",
      "mapping.rir_name": "RIR name",
      "mapping.private_rir": "Private RIR",
      "mapping.circuit_terminations": "Confirmed circuit terminations",
      "mapping.circuit_help":
        "A/Z terminations are created only when both the circuit and its classified location are unambiguous.",
      "mapping.status_rules": "Status rules",
      "mapping.update_rules": "Opt-in updates",
      "mapping.update_help":
        "Comma-separated fields that an existing NetBox object may receive by PATCH.",
      "mapping.update_fields": "description, status",
      "mapping.preview": "Read-only preview",
      "mapping.preview_help":
        "Runs the same planner without creating an approvable or applicable plan.",
      "mapping.preview_save_first":
        "Save the current mapping before running its preview.",
      "mapping.run_preview": "Run preview",
      "mapping.preview_status": "Status",
      "mapping.preview_state.queued": "Queued",
      "mapping.preview_state.running": "Running",
      "mapping.preview_state.completed": "Completed",
      "mapping.preview_state.failed": "Failed",
      "mapping.actions": "Estimated actions",
      "mapping.conflicts": "Conflicts",
      "mapping.warnings": "Warnings",
      "mapping.preview_non_applicable":
        "A preview cannot be approved or applied. Generate an official plan from the project after saving.",
      "mapping.coverage": "Source coverage",
      "mapping.preserved": "Preserved objects",
      "mapping.no_preview": "No preview has been generated for this revision.",
      "mapping.json_expert": "JSON Expert",
      "mapping.json_help":
        "Edit the canonical English schema. Apply stages the JSON locally; Save mapping persists it.",
      "mapping.apply_json": "Apply JSON changes",
      "mapping.discard_json": "Discard JSON changes",
      "mapping.catalog": "Sanitized catalog",
      "mapping.catalog_help":
        "At most five truncated examples are shown. Sensitive fields and complete snapshots never reach the browser.",
      "mapping.json_object": "Mapping must be a JSON object.",
      "mapping.json_applied":
        "JSON changes staged. Save the mapping to persist them.",
      "mapping.invalid_json": "Invalid JSON.",
      "mapping.unsupported_property":
        "The mapping contains an unsupported property.",
      "mapping.invalid_type": "This mapping section has an invalid type.",
      "mapping.invalid_object_policy": "This object policy is invalid.",
      "mapping.invalid_policy":
        "The policy must be migrate, ignore or preserve.",
      "mapping.target_required":
        "Migrated objects require a NetBox target type.",
      "mapping.invalid_target_type": "Unsupported NetBox target type.",
      "mapping.invalid_rule": "The rule must be a JSON object.",
      "mapping.unsupported_rule_property":
        "The rule contains an unsupported property.",
      "mapping.invalid_rule_id": "The rule ID must be stable and URL-safe.",
      "mapping.invalid_action": "The field transformation is not supported.",
      "mapping.invalid_target_kind":
        "The target kind must be a regular or custom field.",
      "mapping.invalid_source_type": "The source object type is not supported.",
      "mapping.invalid_target": "The target must be a canonical field name.",
      "mapping.source_field_required":
        "This transformation requires a source field.",
      "mapping.fields_required":
        "Concatenation requires at least one source field.",
      "mapping.lookup_required": "Lookup requires a conversion table.",
      "mapping.duplicate_target":
        "A source type may write each target field only once.",
      "mapping.invalid_update_rule": "This opt-in update rule is invalid.",
      "mapping.invalid_update_field":
        "This field cannot be updated by the selected object rule.",
      "mapping.invalid_preservation":
        "This preservation decision is not supported.",
      "mapping.duplicate_rule_id":
        "Rule IDs must be unique across the mapping.",
      "mapping.required_property": "A required rule property is missing.",
      "mapping.reference_natural_key_required":
        "References must match by natural key.",
      "mapping.invalid_reference_target":
        "The NetBox reference target type is not supported.",
      "mapping.reference_numeric_id_forbidden":
        "NetBox numeric IDs cannot be stored in mapping rules.",
      "show.stale_plan":
        "This plan is stale because the discovery snapshot or mapping changed. Generate a new plan.",
      "show.definition_locked":
        "Discovery, mapping and new planning stay locked until the current execution is resumed and verified.",
      "show.truncated":
        "Only the first 500 items are shown here. The audit bundle contains the complete data.",
      "show.reuse_warning":
        "{{type}} {{id}} differs in NetBox; reuse preserves the existing values.",
      "show.schema_warning":
        "NetBox did not expose write metadata for {{endpoint}}.",
      "show.use_sandbox": "Use the internal disposable NetBox sandbox",
      "show.change_target": "Target-specific planning",
      "show.change_target_help":
        "After a verified rehearsal, refresh only the NetBox target and generate a new production-specific plan with the same source and mapping.",
      "show.refresh_target": "Refresh NetBox target",
      "conflict.target_field_constraint":
        "A value violates a constraint reported by this NetBox version.",
      "conflict.target_identity_collision":
        "The resulting identity conflicts with another existing NetBox object.",
      "conflict.location_classification_required":
        "Every phpIPAM location must be classified as a Site or Location.",
      "conflict.location_site_required":
        "A Location requires an explicitly selected Site.",
      "conflict.rack_site_required":
        "A rack requires a resolved Site and optional Location.",
      "conflict.device_prerequisites_required":
        "The device is missing a Site, Role, Manufacturer or Device Type mapping.",
      "conflict.interface_type_required":
        "The interface requires an approved NetBox interface type.",
      "conflict.circuit_prerequisites_required":
        "The circuit requires Provider and Circuit Type references.",
      "conflict.circuit_termination_location_required":
        "The circuit termination requires an unambiguous classified location.",
      "conflict.asn_rir_required":
        "Every ASN requires an approved RIR reference.",
      "conflict.nat_ip_pair_required":
        "The NAT relation requires two migrated IP addresses.",
      "conflict.auxiliary_creation_unapproved":
        "A required auxiliary object does not exist and its creation was not approved.",
      "conflict.preserved_dependency":
        "A required dependency is configured for preservation instead of migration.",
      "conflict.missing_relation_subject":
        "The relationship subject could not be resolved.",
      "conflict.invalid_field_value":
        "A field transformation produced an incompatible value.",
      "conflict.invalid_planner_intent":
        "The planner produced an invalid resource intent.",
      "conflict.prefix_folder_preserved":
        "A phpIPAM folder has no safe NetBox equivalent and remains preserved.",
      "conflict.device_ip_without_port":
        "The IP references a device but has no port, so it remains unassigned.",
      "conflict.pat_preserved":
        "PAT or port-based NAT is preserved and is never partially converted.",
      "conflict.nat_confirmation_required":
        "The static NAT pair remains preserved until an operator confirms it.",
      "conflict.primary_ip_ambiguous":
        "Primary IP was not set because the source correspondence is ambiguous.",
      "conflict.ambiguous_target":
        "More than one NetBox object matches this source.",
      "conflict.custom_field_mapping_type_conflict":
        "Custom-field rules disagree on the target type.",
      "conflict.custom_field_scope_mismatch":
        "The existing custom field is not assigned to every required object type.",
      "conflict.custom_field_type_mismatch":
        "The existing custom field has an incompatible type.",
      "conflict.dependency_cycle": "The object dependencies contain a cycle.",
      "conflict.duplicate_source_identity":
        "The source contains a duplicate object identity.",
      "conflict.duplicate_target_claim":
        "Multiple source objects claim the same NetBox target.",
      "conflict.invalid_custom_field_value":
        "A custom-field value cannot be safely converted.",
      "conflict.invalid_mapping": "The mapping policy is invalid.",
      "conflict.linked_target_missing":
        "A previously linked NetBox object no longer exists.",
      "conflict.linked_target_type_mismatch":
        "A persistent object link points to the wrong NetBox type.",
      "conflict.missing_dependency": "A required source dependency is missing.",
      "conflict.missing_identity":
        "The source object has no safe migration identity.",
      "conflict.target_required_field_missing":
        "A field required by this NetBox version is missing.",
      "conflict.target_write_schema_unavailable":
        "NetBox did not confirm write compatibility for this mutation.",
      "conflict.unsupported_target_choice":
        "The mapped value is not accepted by this NetBox version.",
      "conflict.unknown": "Unknown blocking conflict.",
      "status.draft": "Draft",
      "status.discovering": "Discovering",
      "status.discovered": "Discovered",
      "status.planning": "Planning",
      "status.planned": "Planned",
      "status.approved": "Approved",
      "status.applying": "Applying",
      "status.applied": "Applied",
      "status.verifying": "Verifying",
      "status.verified": "Verified",
      "status.partially_applied": "Partially applied",
      "status.failed": "Failed",
      "operation.create": "Create",
      "operation.reuse": "Reuse",
      "operation.update": "Update",
      "operation.ignore": "Ignore",
      "execution.pending": "Pending",
      "execution.applying": "Applying",
      "execution.applied": "Applied",
      "execution.verifying": "Verifying",
      "execution.verified": "Verified",
      "execution.verification_failed": "Verification failed",
      "execution.failed": "Failed",
    },
  },
  pt_BR: {
    translation: {
      "language.change": "Alterar idioma",
      "language.updating": "Alterando idioma",
      "common.back": "Voltar aos projetos",
      "common.create": "Criar",
      "common.status": "Status",
      "welcome.title": "Migre seu IPAM com segurança.",
      "welcome.body":
        "Descubra, mapeie, revise e aplique uma migração phpIPAM → NetBox com plano auditável.",
      "welcome.setup": "Configurar instalação",
      "welcome.login": "Entrar",
      "setup.title": "Reivindicar instalação",
      "setup.help": "Use o token mostrado por",
      "setup.token": "Token",
      "setup.name": "Nome",
      "setup.email": "Email",
      "setup.password": "Senha",
      "setup.password_help":
        "14–128 caracteres, com maiúscula, minúscula, número e símbolo.",
      "setup.confirm": "Confirmar senha",
      "setup.submit": "Criar owner",
      "login.title": "Entrar no IpamFerry",
      "login.submit": "Entrar",
      "dashboard.title": "Projetos de migração",
      "dashboard.new": "Novo projeto",
      "dashboard.empty": "Crie um projeto para iniciar a descoberta.",
      "dashboard.plans": "{{count}} plano(s) gerado(s)",
      "projects.new": "Novo projeto",
      "projects.name": "Nome",
      "projects.source": "Origem",
      "projects.existing": "Projetos existentes",
      "projects.api": "API phpIPAM",
      "projects.dump": "mysqldump SQL",
      "projects.locale": "Idioma dos artefatos",
      "show.import": "1. Importar mysqldump",
      "show.sql_safe": "O SQL é analisado, nunca executado.",
      "show.dump_file": "Dump SQL",
      "show.read_dump": "Ler dump",
      "show.discover": "1. Descobrir APIs",
      "show.tokens_safe":
        "Os tokens ficam apenas na memória do navegador e da requisição.",
      "show.run_discovery": "Executar descoberta",
      "show.plan_apply": "4. Revisar, aprovar e aplicar",
      "show.generate_plan": "Gerar plano",
      "show.actions_conflicts": "{{actions}} ações, {{conflicts}} conflitos",
      "show.mapping_warning":
        "{{type}} requer revisão de mapeamento antes da exportação para o NetBox.",
      "show.confirm_apply": "Confirmo a aplicação deste plano exato e aprovado",
      "show.apply": "Aplicar via API",
      "show.bundle": "Baixar bundle auditável",
      "show.netbox_url": "URL NetBox",
      "show.netbox_token": "Token NetBox",
      "show.phpipam_url": "URL phpIPAM",
      "show.phpipam_app": "App ID",
      "show.phpipam_token": "Token phpIPAM",
      "show.inventory": "2. Inventário canônico",
      "show.inventory_empty":
        "Execute a descoberta para criar o inventário canônico sanitizado.",
      "show.mapping": "3. Política de mapeamento",
      "show.mapping_help":
        "Edite a política versionada, sem código executável, no Mapping Studio.",
      "show.artifact_locale_help":
        "Usado apenas nos relatórios, arquivos de preservação e bundle auditável. Não altera o idioma da interface.",
      "show.save_artifact_locale": "Atualizar idioma dos artefatos",
      "show.mapping_json": "JSON de mapeamento",
      "show.save_mapping": "Salvar mapeamento",
      "show.plan_help":
        "O planejamento é somente leitura. Conflitos impedem aprovação e aplicação.",
      "show.planning": "Planejando…",
      "show.conflicts": "Conflitos impeditivos",
      "show.actions": "Ações planejadas",
      "show.operation": "Operação",
      "show.source": "Origem",
      "show.target": "Destino NetBox",
      "show.identity": "Chave natural",
      "show.confirm_approve": "Revisei o diff e aprovo este fingerprint exato",
      "show.approve": "Aprovar plano",
      "show.applying": "Aplicando lote…",
      "show.resume": "Retomar aplicação",
      "show.execution": "Execução #{{id}}",
      "show.progress": "{{completed}} de {{total}} ações concluídas",
      "show.verify": "5. Verificar estado do destino",
      "show.verifying": "Verificando…",
      "show.run_verify": "Executar verificação",
      "mapping.open": "Abrir Mapping Studio",
      "mapping.title": "Mapping Studio",
      "mapping.back": "Voltar ao projeto",
      "mapping.revision": "Revisão {{revision}}",
      "mapping.undo": "Desfazer",
      "mapping.redo": "Refazer",
      "mapping.save": "Salvar mapeamento",
      "mapping.saving": "Salvando…",
      "mapping.saved": "Mapeamento salvo.",
      "mapping.save_failed": "Não foi possível salvar o mapeamento.",
      "mapping.unsaved_confirm":
        "Descartar alterações não salvas no mapeamento?",
      "mapping.locked":
        "O mapeamento fica bloqueado enquanto uma execução de migração estiver ativa.",
      "mapping.read_only":
        "Seu papel reader permite consultar este mapeamento, mas não alterá-lo.",
      "mapping.validation_errors": "Erros de validação do mapeamento",
      "mapping.sections": "Seções do Mapping Studio",
      "mapping.tab.overview": "Visão geral",
      "mapping.tab.objects": "Objetos",
      "mapping.tab.references": "Referências",
      "mapping.tab.fields": "Campos",
      "mapping.tab.status": "Status / atualizações",
      "mapping.tab.relations": "Relações",
      "mapping.tab.preview": "Preview",
      "mapping.tab.json": "JSON Expert",
      "mapping.source_objects": "Objetos de origem",
      "mapping.target_objects": "Objetos no NetBox",
      "mapping.object_types": "tipos de objeto",
      "mapping.suggestions": "Sugestões",
      "mapping.review_required": "a revisão é sempre obrigatória",
      "mapping.v1_detected": "Schema de mapeamento v1 detectado",
      "mapping.v1_help":
        "Revise a conversão proposta. Ela só será persistida após salvar explicitamente.",
      "mapping.upgrade": "Converter para v2",
      "mapping.deterministic_suggestions": "Sugestões determinísticas",
      "mapping.suggestions_help":
        "As sugestões usam nomes, slugs, tipos e chaves naturais. Nada é aceito automaticamente.",
      "mapping.accept_all": "Aceitar todas",
      "mapping.no_suggestions": "Nenhuma sugestão pendente.",
      "mapping.reason.matching_object_type": "tipo de objeto correspondente",
      "mapping.reason.matching_field_name": "nome de campo correspondente",
      "mapping.reason.matching_type_and_natural_key":
        "tipo e chave natural correspondentes",
      "mapping.reason.matching_slug":
        "nome normalizado para slug de chave natural",
      "mapping.accept": "Aceitar sugestão",
      "mapping.object_policies": "Políticas de objetos",
      "mapping.object_help":
        "Escolha o que será migrado, preservado no bundle auditável ou ignorado explicitamente.",
      "mapping.records": "registros",
      "mapping.fields": "campos",
      "mapping.policy.migrate": "Migrar",
      "mapping.policy.preserve": "Preservar",
      "mapping.policy.ignore": "Ignorar",
      "mapping.preservation_handling": "Tratamento de preservação",
      "mapping.preservation.report": "Incluir no relatório",
      "mapping.preservation.note": "Mapear para nota",
      "mapping.preservation.custom_field": "Mapear para custom field",
      "mapping.preservation.discard": "Descartar explicitamente",
      "mapping.target_type": "Tipo de destino no NetBox",
      "mapping.field_rules": "Regras de campos",
      "mapping.field_help":
        "Somente transformações declarativas e não executáveis são aceitas.",
      "mapping.source_field": "Campo de origem",
      "mapping.target_field": "Campo de destino",
      "mapping.action.copy": "Copiar",
      "mapping.action.ignore": "Ignorar",
      "mapping.action.fixed": "Valor fixo",
      "mapping.action.concat": "Concatenar",
      "mapping.action.normalize": "Normalizar",
      "mapping.action.lookup": "Tabela de conversão",
      "mapping.remove": "Remover regra",
      "mapping.no_field_rules": "Ainda não há regras de campos.",
      "mapping.target_regular": "Campo do objeto",
      "mapping.target_custom": "Campo personalizado",
      "mapping.fixed_value": "Valor fixo",
      "mapping.concat_fields": "Campos separados por vírgula",
      "mapping.separator": "Separador",
      "mapping.lookup_table": "Tabela de conversão lookup",
      "mapping.reference_rules": "Regras de referências",
      "mapping.reference_help":
        "Resolva referências por chave natural, nunca por ID numérico do NetBox.",
      "mapping.no_reference_rules": "Ainda não há regras de referências.",
      "mapping.relation_rules": "Regras de relações",
      "mapping.relation_help":
        "Habilite apenas relações cujos pré-requisitos possam ser comprovados.",
      "mapping.no_relation_rules": "Ainda não há regras de relações.",
      "mapping.source_type": "Tipo de origem",
      "mapping.relation": "Relação",
      "mapping.enabled": "Habilitada",
      "mapping.relation.location_classification": "Classificação de locais",
      "mapping.relation.device_defaults": "Pré-requisitos de devices",
      "mapping.relation.customer_contacts": "Contatos de clientes",
      "mapping.relation.asn_defaults": "ASN / RIR",
      "mapping.relation.circuit_terminations": "Terminações de circuitos",
      "mapping.relation.primary_ip": "IP primário",
      "mapping.relation.nat_1to1": "NAT estático 1:1",
      "mapping.location_classification": "Classificar locais do phpIPAM",
      "mapping.location_help":
        "Cada local deve virar um Site ou uma Location subordinada a um Site escolhido explicitamente.",
      "mapping.choose": "Escolha…",
      "mapping.as_site": "Site",
      "mapping.as_location": "Location",
      "mapping.choose_site": "Escolha o Site pai…",
      "mapping.no_locations": "Nenhum local foi descoberto.",
      "mapping.fallback_site_name": "Nome do Site fallback",
      "mapping.fallback_help":
        "Usado somente para devices sem local na origem.",
      "mapping.device_categories": "Requisitos por categoria de device",
      "mapping.device_help":
        "Cada categoria exige Role, Manufacturer, Device Type físico e tipo de Interface.",
      "mapping.device_exceptions": "Exceções individuais de devices",
      "mapping.device_exceptions_help":
        "Substitua o papel da categoria, hardware ou tipo de interface somente nos devices que precisam de exceção.",
      "mapping.device_role": "Papel de device no NetBox",
      "mapping.override_hardware": "Substituir hardware",
      "mapping.override_manufacturer": "Substituir fabricante",
      "mapping.override_device_type": "Substituir modelo físico",
      "mapping.override_interface_type": "Substituir tipo de interface",
      "mapping.manufacturer": "Fabricante",
      "mapping.device_type": "Modelo físico do device",
      "mapping.interface_type": "Tipo de interface no NetBox",
      "mapping.no_device_categories":
        "Nenhuma categoria de device do phpIPAM foi descoberta.",
      "mapping.nat_confirmation": "Confirmar NAT estático 1:1",
      "mapping.nat_help":
        "Confirme apenas pares IP-para-IP sem portas. PAT continua preservado.",
      "mapping.advanced_relations": "Campos JSON avançados de relações",
      "mapping.contact_role": "Contact Role",
      "mapping.contact_help":
        "Os contatos só são criados e atribuídos ao Tenant após a aprovação deste papel.",
      "mapping.contact_role_name": "Nome do Contact Role",
      "mapping.rir_defaults": "RIR para os ASNs descobertos",
      "mapping.rir_help": "O NetBox exige que cada ASN referencie um RIR.",
      "mapping.rir_name": "Nome do RIR",
      "mapping.private_rir": "RIR privado",
      "mapping.circuit_terminations": "Terminações de circuito confirmadas",
      "mapping.circuit_help":
        "Terminações A/Z só são criadas quando o circuito e o local classificado forem inequívocos.",
      "mapping.status_rules": "Regras de status",
      "mapping.update_rules": "Atualizações opt-in",
      "mapping.update_help":
        "Campos separados por vírgula que um objeto existente no NetBox poderá receber via PATCH.",
      "mapping.update_fields": "description, status",
      "mapping.preview": "Preview somente leitura",
      "mapping.preview_help":
        "Executa o mesmo planejador sem criar um plano aprovável ou aplicável.",
      "mapping.preview_save_first":
        "Salve o mapeamento atual antes de executar o preview.",
      "mapping.run_preview": "Executar preview",
      "mapping.preview_status": "Status",
      "mapping.preview_state.queued": "Na fila",
      "mapping.preview_state.running": "Executando",
      "mapping.preview_state.completed": "Concluído",
      "mapping.preview_state.failed": "Falhou",
      "mapping.actions": "Ações estimadas",
      "mapping.conflicts": "Conflitos",
      "mapping.warnings": "Avisos",
      "mapping.preview_non_applicable":
        "Um preview não pode ser aprovado nem aplicado. Após salvar, gere um plano oficial pelo projeto.",
      "mapping.coverage": "Cobertura da origem",
      "mapping.preserved": "Objetos preservados",
      "mapping.no_preview": "Nenhum preview foi gerado para esta revisão.",
      "mapping.json_expert": "JSON Expert",
      "mapping.json_help":
        "Edite o schema canônico em inglês. Aplicar prepara o JSON localmente; Salvar mapeamento o persiste.",
      "mapping.apply_json": "Aplicar alterações JSON",
      "mapping.discard_json": "Descartar alterações JSON",
      "mapping.catalog": "Catálogo sanitizado",
      "mapping.catalog_help":
        "São mostrados no máximo cinco exemplos truncados. Campos sensíveis e snapshots completos nunca chegam ao navegador.",
      "mapping.json_object": "O mapeamento deve ser um objeto JSON.",
      "mapping.json_applied":
        "Alterações JSON preparadas. Salve o mapeamento para persistir.",
      "mapping.invalid_json": "JSON inválido.",
      "mapping.unsupported_property":
        "O mapeamento contém uma propriedade não suportada.",
      "mapping.invalid_type":
        "Esta seção do mapeamento possui um tipo inválido.",
      "mapping.invalid_object_policy": "Esta política de objeto é inválida.",
      "mapping.invalid_policy":
        "A política deve ser migrate, ignore ou preserve.",
      "mapping.target_required":
        "Objetos migrados exigem um tipo de destino no NetBox.",
      "mapping.invalid_target_type": "Tipo de destino do NetBox não suportado.",
      "mapping.invalid_rule": "A regra deve ser um objeto JSON.",
      "mapping.unsupported_rule_property":
        "A regra contém uma propriedade não suportada.",
      "mapping.invalid_rule_id":
        "O ID da regra deve ser estável e seguro para URL.",
      "mapping.invalid_action": "A transformação de campo não é suportada.",
      "mapping.invalid_target_kind":
        "O destino deve ser um campo regular ou customizado.",
      "mapping.invalid_source_type":
        "O tipo de objeto da origem não é suportado.",
      "mapping.invalid_target": "O destino deve ser um nome de campo canônico.",
      "mapping.source_field_required":
        "Esta transformação exige um campo de origem.",
      "mapping.fields_required":
        "A concatenação exige pelo menos um campo de origem.",
      "mapping.lookup_required": "Lookup exige uma tabela de conversão.",
      "mapping.duplicate_target":
        "Um tipo de origem só pode escrever uma vez em cada campo de destino.",
      "mapping.invalid_update_rule":
        "Esta regra de atualização opt-in é inválida.",
      "mapping.invalid_update_field":
        "Este campo não pode ser atualizado pela regra do objeto selecionado.",
      "mapping.invalid_preservation":
        "Esta decisão de preservação não é suportada.",
      "mapping.duplicate_rule_id":
        "Os IDs das regras devem ser únicos em todo o mapeamento.",
      "mapping.required_property":
        "Falta uma propriedade obrigatória da regra.",
      "mapping.reference_natural_key_required":
        "As referências devem ser resolvidas por chave natural.",
      "mapping.invalid_reference_target":
        "O tipo de destino da referência no NetBox não é suportado.",
      "mapping.reference_numeric_id_forbidden":
        "IDs numéricos do NetBox não podem ser armazenados nas regras de mapeamento.",
      "show.stale_plan":
        "Este plano está desatualizado porque a descoberta ou o mapeamento mudou. Gere um novo plano.",
      "show.definition_locked":
        "A descoberta, o mapeamento e um novo planejamento ficam bloqueados até a execução atual ser retomada e verificada.",
      "show.truncated":
        "Apenas os primeiros 500 itens aparecem aqui. O bundle auditável contém os dados completos.",
      "show.reuse_warning":
        "{{type}} {{id}} difere no NetBox; a reutilização preserva os valores existentes.",
      "show.schema_warning":
        "O NetBox não expôs metadados de escrita para {{endpoint}}.",
      "show.use_sandbox": "Usar o sandbox NetBox interno e descartável",
      "show.change_target": "Planejamento específico por destino",
      "show.change_target_help":
        "Após um ensaio verificado, redescubra apenas o destino NetBox e gere um novo plano específico para produção com a mesma origem e o mesmo mapeamento.",
      "show.refresh_target": "Redescobrir destino NetBox",
      "conflict.target_field_constraint":
        "Um valor viola uma restrição informada por esta versão do NetBox.",
      "conflict.target_identity_collision":
        "A identidade resultante conflita com outro objeto existente no NetBox.",
      "conflict.location_classification_required":
        "Cada local do phpIPAM deve ser classificado como Site ou Location.",
      "conflict.location_site_required":
        "Uma Location exige um Site escolhido explicitamente.",
      "conflict.rack_site_required":
        "Um rack exige Site resolvido e Location opcional.",
      "conflict.device_prerequisites_required":
        "O device não possui mapeamento de Site, Role, Manufacturer ou Device Type.",
      "conflict.interface_type_required":
        "A interface exige um tipo de interface do NetBox aprovado.",
      "conflict.circuit_prerequisites_required":
        "O circuito exige referências de Provider e Circuit Type.",
      "conflict.circuit_termination_location_required":
        "A terminação do circuito exige um local classificado inequívoco.",
      "conflict.asn_rir_required":
        "Cada ASN exige uma referência RIR aprovada.",
      "conflict.nat_ip_pair_required":
        "A relação NAT exige dois endereços IP migrados.",
      "conflict.auxiliary_creation_unapproved":
        "Um objeto auxiliar obrigatório não existe e sua criação não foi aprovada.",
      "conflict.preserved_dependency":
        "Uma dependência obrigatória está configurada para preservação, não migração.",
      "conflict.missing_relation_subject":
        "Não foi possível resolver o objeto da relação.",
      "conflict.invalid_field_value":
        "Uma transformação de campo produziu valor incompatível.",
      "conflict.invalid_planner_intent":
        "O planejador produziu uma intenção de recurso inválida.",
      "conflict.prefix_folder_preserved":
        "Uma pasta do phpIPAM não possui equivalente seguro no NetBox e continua preservada.",
      "conflict.device_ip_without_port":
        "O IP referencia um device, mas não possui porta e continua sem atribuição.",
      "conflict.pat_preserved":
        "PAT ou NAT com portas é preservado e nunca convertido parcialmente.",
      "conflict.nat_confirmation_required":
        "O par NAT estático continua preservado até a confirmação de um operador.",
      "conflict.primary_ip_ambiguous":
        "O IP primário não foi definido porque a correspondência da origem é ambígua.",
      "conflict.ambiguous_target":
        "Mais de um objeto do NetBox corresponde a esta origem.",
      "conflict.custom_field_mapping_type_conflict":
        "As regras de campo personalizado discordam sobre o tipo de destino.",
      "conflict.custom_field_scope_mismatch":
        "O campo personalizado existente não está atribuído a todos os tipos necessários.",
      "conflict.custom_field_type_mismatch":
        "O campo personalizado existente tem tipo incompatível.",
      "conflict.dependency_cycle":
        "As dependências dos objetos contêm um ciclo.",
      "conflict.duplicate_source_identity":
        "A origem contém uma identidade de objeto duplicada.",
      "conflict.duplicate_target_claim":
        "Vários objetos de origem reivindicam o mesmo destino no NetBox.",
      "conflict.invalid_custom_field_value":
        "Um valor de campo personalizado não pode ser convertido com segurança.",
      "conflict.invalid_mapping": "A política de mapeamento é inválida.",
      "conflict.linked_target_missing":
        "Um objeto do NetBox vinculado anteriormente não existe mais.",
      "conflict.linked_target_type_mismatch":
        "Um vínculo persistente aponta para o tipo errado no NetBox.",
      "conflict.missing_dependency":
        "Uma dependência obrigatória da origem está ausente.",
      "conflict.missing_identity":
        "O objeto de origem não tem identidade segura para migração.",
      "conflict.target_required_field_missing":
        "Falta um campo exigido por esta versão do NetBox.",
      "conflict.target_write_schema_unavailable":
        "O NetBox não confirmou compatibilidade de escrita para esta alteração.",
      "conflict.unsupported_target_choice":
        "O valor mapeado não é aceito por esta versão do NetBox.",
      "conflict.unknown": "Conflito impeditivo desconhecido.",
      "status.draft": "Rascunho",
      "status.discovering": "Descobrindo",
      "status.discovered": "Descoberto",
      "status.planning": "Planejando",
      "status.planned": "Planejado",
      "status.approved": "Aprovado",
      "status.applying": "Aplicando",
      "status.applied": "Aplicado",
      "status.verifying": "Verificando",
      "status.verified": "Verificado",
      "status.partially_applied": "Parcialmente aplicado",
      "status.failed": "Falhou",
      "operation.create": "Criar",
      "operation.reuse": "Reutilizar",
      "operation.update": "Atualizar",
      "operation.ignore": "Ignorar",
      "execution.pending": "Pendente",
      "execution.applying": "Aplicando",
      "execution.applied": "Aplicado",
      "execution.verifying": "Verificando",
      "execution.verified": "Verificado",
      "execution.verification_failed": "Verificação falhou",
      "execution.failed": "Falhou",
    },
  },
  es: {
    translation: {
      "language.change": "Cambiar idioma",
      "language.updating": "Cambiando idioma",
      "common.back": "Volver a proyectos",
      "common.create": "Crear",
      "common.status": "Estado",
      "welcome.title": "Migra tu IPAM de forma segura.",
      "welcome.body":
        "Descubre, mapea, revisa y aplica una migración de phpIPAM a NetBox con un plan auditable.",
      "welcome.setup": "Configurar instalación",
      "welcome.login": "Iniciar sesión",
      "setup.title": "Reclamar instalación",
      "setup.help": "Usa el token mostrado por",
      "setup.token": "Token",
      "setup.name": "Nombre",
      "setup.email": "Correo electrónico",
      "setup.password": "Contraseña",
      "setup.password_help":
        "14–128 caracteres, con mayúscula, minúscula, número y símbolo.",
      "setup.confirm": "Confirmar contraseña",
      "setup.submit": "Crear propietario",
      "login.title": "Iniciar sesión en IpamFerry",
      "login.submit": "Iniciar sesión",
      "dashboard.title": "Proyectos de migración",
      "dashboard.new": "Nuevo proyecto",
      "dashboard.empty": "Crea un proyecto para iniciar el descubrimiento.",
      "dashboard.plans": "{{count}} plan(es) generado(s)",
      "projects.new": "Nuevo proyecto",
      "projects.name": "Nombre",
      "projects.source": "Origen",
      "projects.existing": "Proyectos existentes",
      "projects.api": "API de phpIPAM",
      "projects.dump": "mysqldump SQL",
      "projects.locale": "Idioma de artefactos",
      "show.import": "1. Importar mysqldump",
      "show.sql_safe": "El SQL se analiza y nunca se ejecuta.",
      "show.dump_file": "Volcado SQL",
      "show.read_dump": "Leer volcado",
      "show.discover": "1. Descubrir API",
      "show.tokens_safe":
        "Los tokens permanecen solo en la memoria del navegador y de la solicitud.",
      "show.run_discovery": "Ejecutar descubrimiento",
      "show.plan_apply": "4. Revisar, aprobar y aplicar",
      "show.generate_plan": "Generar plan",
      "show.actions_conflicts":
        "{{actions}} acciones, {{conflicts}} conflictos",
      "show.mapping_warning":
        "{{type}} requiere revisión de mapeo antes de exportar a NetBox.",
      "show.confirm_apply":
        "Confirmo la aplicación de este plan exacto y aprobado",
      "show.apply": "Aplicar mediante API",
      "show.bundle": "Descargar bundle auditable",
      "show.netbox_url": "URL de NetBox",
      "show.netbox_token": "Token de NetBox",
      "show.phpipam_url": "URL de phpIPAM",
      "show.phpipam_app": "ID de aplicación",
      "show.phpipam_token": "Token de phpIPAM",
      "show.inventory": "2. Inventario canónico",
      "show.inventory_empty":
        "Ejecuta el descubrimiento para crear el inventario canónico saneado.",
      "show.mapping": "3. Política de mapeo",
      "show.mapping_help":
        "Edita la política versionada sin código ejecutable en Mapping Studio.",
      "show.artifact_locale_help":
        "Se usa solo para informes, archivos de preservación y el bundle auditable. No cambia el idioma de la interfaz.",
      "show.save_artifact_locale": "Actualizar idioma de artefactos",
      "show.mapping_json": "JSON de mapeo",
      "show.save_mapping": "Guardar mapeo",
      "show.plan_help":
        "La planificación es de solo lectura. Los conflictos impiden aprobar y aplicar.",
      "show.planning": "Planificando…",
      "show.conflicts": "Conflictos bloqueantes",
      "show.actions": "Acciones planificadas",
      "show.operation": "Operación",
      "show.source": "Origen",
      "show.target": "Destino NetBox",
      "show.identity": "Clave natural",
      "show.confirm_approve": "Revisé el diff y apruebo esta huella exacta",
      "show.approve": "Aprobar plan",
      "show.applying": "Aplicando lote…",
      "show.resume": "Reanudar aplicación",
      "show.execution": "Ejecución #{{id}}",
      "show.progress": "{{completed}} de {{total}} acciones completadas",
      "show.verify": "5. Verificar estado del destino",
      "show.verifying": "Verificando…",
      "show.run_verify": "Ejecutar verificación",
      "mapping.open": "Abrir Mapping Studio",
      "mapping.title": "Mapping Studio",
      "mapping.back": "Volver al proyecto",
      "mapping.revision": "Revisión {{revision}}",
      "mapping.undo": "Deshacer",
      "mapping.redo": "Rehacer",
      "mapping.save": "Guardar mapeo",
      "mapping.saving": "Guardando…",
      "mapping.saved": "Mapeo guardado.",
      "mapping.save_failed": "No se pudo guardar el mapeo.",
      "mapping.unsaved_confirm": "¿Descartar los cambios de mapeo sin guardar?",
      "mapping.locked":
        "El mapeo permanece bloqueado mientras haya una ejecución de migración activa.",
      "mapping.read_only":
        "Tu rol de lector permite consultar este mapeo, pero no modificarlo.",
      "mapping.validation_errors": "Errores de validación del mapeo",
      "mapping.sections": "Secciones de Mapping Studio",
      "mapping.tab.overview": "Resumen",
      "mapping.tab.objects": "Objetos",
      "mapping.tab.references": "Referencias",
      "mapping.tab.fields": "Campos",
      "mapping.tab.status": "Estados / actualizaciones",
      "mapping.tab.relations": "Relaciones",
      "mapping.tab.preview": "Vista previa",
      "mapping.tab.json": "JSON Expert",
      "mapping.source_objects": "Objetos de origen",
      "mapping.target_objects": "Objetos en NetBox",
      "mapping.object_types": "tipos de objeto",
      "mapping.suggestions": "Sugerencias",
      "mapping.review_required": "la revisión siempre es obligatoria",
      "mapping.v1_detected": "Se detectó un esquema de mapeo v1",
      "mapping.v1_help":
        "Revisa la conversión propuesta. Solo se guardará después de una acción explícita.",
      "mapping.upgrade": "Convertir a v2",
      "mapping.deterministic_suggestions": "Sugerencias deterministas",
      "mapping.suggestions_help":
        "Las sugerencias usan nombres, slugs, tipos y claves naturales. Nada se acepta automáticamente.",
      "mapping.accept_all": "Aceptar todas",
      "mapping.no_suggestions": "No hay sugerencias pendientes.",
      "mapping.reason.matching_object_type": "tipo de objeto coincidente",
      "mapping.reason.matching_field_name": "nombre de campo coincidente",
      "mapping.reason.matching_type_and_natural_key":
        "tipo y clave natural coincidentes",
      "mapping.reason.matching_slug":
        "nombre normalizado a slug de clave natural",
      "mapping.accept": "Aceptar sugerencia",
      "mapping.object_policies": "Políticas de objetos",
      "mapping.object_help":
        "Elige qué se migra, se conserva en el bundle de auditoría o se ignora explícitamente.",
      "mapping.records": "registros",
      "mapping.fields": "campos",
      "mapping.policy.migrate": "Migrar",
      "mapping.policy.preserve": "Conservar",
      "mapping.policy.ignore": "Ignorar",
      "mapping.preservation_handling": "Tratamiento de conservación",
      "mapping.preservation.report": "Incluir en el informe",
      "mapping.preservation.note": "Mapear a nota",
      "mapping.preservation.custom_field": "Mapear a custom field",
      "mapping.preservation.discard": "Descartar explícitamente",
      "mapping.target_type": "Tipo de destino en NetBox",
      "mapping.field_rules": "Reglas de campos",
      "mapping.field_help":
        "Solo se admiten transformaciones declarativas y no ejecutables.",
      "mapping.source_field": "Campo de origen",
      "mapping.target_field": "Campo de destino",
      "mapping.action.copy": "Copiar",
      "mapping.action.ignore": "Ignorar",
      "mapping.action.fixed": "Valor fijo",
      "mapping.action.concat": "Concatenar",
      "mapping.action.normalize": "Normalizar",
      "mapping.action.lookup": "Tabla de conversión",
      "mapping.remove": "Eliminar regla",
      "mapping.no_field_rules": "Todavía no hay reglas de campos.",
      "mapping.target_regular": "Campo del objeto",
      "mapping.target_custom": "Campo personalizado",
      "mapping.fixed_value": "Valor fijo",
      "mapping.concat_fields": "Campos separados por comas",
      "mapping.separator": "Separador",
      "mapping.lookup_table": "Tabla de conversión lookup",
      "mapping.reference_rules": "Reglas de referencias",
      "mapping.reference_help":
        "Resuelve referencias por clave natural, nunca por un ID numérico de NetBox.",
      "mapping.no_reference_rules": "Todavía no hay reglas de referencias.",
      "mapping.relation_rules": "Reglas de relaciones",
      "mapping.relation_help":
        "Habilita únicamente relaciones cuyos requisitos previos puedan demostrarse.",
      "mapping.no_relation_rules": "Todavía no hay reglas de relaciones.",
      "mapping.source_type": "Tipo de origen",
      "mapping.relation": "Relación",
      "mapping.enabled": "Habilitada",
      "mapping.relation.location_classification":
        "Clasificación de ubicaciones",
      "mapping.relation.device_defaults": "Requisitos de dispositivos",
      "mapping.relation.customer_contacts": "Contactos de clientes",
      "mapping.relation.asn_defaults": "ASN / RIR",
      "mapping.relation.circuit_terminations": "Terminaciones de circuitos",
      "mapping.relation.primary_ip": "IP primaria",
      "mapping.relation.nat_1to1": "NAT estático 1:1",
      "mapping.location_classification": "Clasificar ubicaciones de phpIPAM",
      "mapping.location_help":
        "Cada ubicación debe convertirse en un Site o una Location subordinada a un Site seleccionado explícitamente.",
      "mapping.choose": "Elegir…",
      "mapping.as_site": "Site",
      "mapping.as_location": "Location",
      "mapping.choose_site": "Elegir Site principal…",
      "mapping.no_locations": "No se descubrieron ubicaciones.",
      "mapping.fallback_site_name": "Nombre del Site alternativo",
      "mapping.fallback_help":
        "Se usa solo para dispositivos sin ubicación de origen.",
      "mapping.device_categories": "Requisitos por categoría de dispositivo",
      "mapping.device_help":
        "Cada categoría requiere Role, Manufacturer, Device Type físico y tipo de Interface.",
      "mapping.device_exceptions": "Excepciones individuales de dispositivos",
      "mapping.device_exceptions_help":
        "Sustituya el rol de la categoría, hardware o tipo de interfaz solo en los dispositivos que necesiten una excepción.",
      "mapping.device_role": "Rol de dispositivo en NetBox",
      "mapping.override_hardware": "Sustituir hardware",
      "mapping.override_manufacturer": "Sustituir fabricante",
      "mapping.override_device_type": "Sustituir modelo físico",
      "mapping.override_interface_type": "Sustituir tipo de interfaz",
      "mapping.manufacturer": "Fabricante",
      "mapping.device_type": "Modelo físico del dispositivo",
      "mapping.interface_type": "Tipo de interfaz de NetBox",
      "mapping.no_device_categories":
        "No se descubrieron categorías de dispositivos de phpIPAM.",
      "mapping.nat_confirmation": "Confirmar NAT estático 1:1",
      "mapping.nat_help":
        "Confirma únicamente pares IP a IP sin puertos. PAT se conserva.",
      "mapping.advanced_relations": "Campos JSON avanzados de relaciones",
      "mapping.contact_role": "Contact Role",
      "mapping.contact_help":
        "Los contactos solo se crean y asignan a su Tenant después de aprobar este rol.",
      "mapping.contact_role_name": "Nombre del Contact Role",
      "mapping.rir_defaults": "RIR para los ASN descubiertos",
      "mapping.rir_help":
        "NetBox requiere que cada ASN haga referencia a un RIR.",
      "mapping.rir_name": "Nombre del RIR",
      "mapping.private_rir": "RIR privado",
      "mapping.circuit_terminations": "Terminaciones de circuito confirmadas",
      "mapping.circuit_help":
        "Las terminaciones A/Z solo se crean cuando el circuito y su ubicación clasificada son inequívocos.",
      "mapping.status_rules": "Reglas de estado",
      "mapping.update_rules": "Actualizaciones opt-in",
      "mapping.update_help":
        "Campos separados por comas que un objeto existente de NetBox puede recibir mediante PATCH.",
      "mapping.update_fields": "description, status",
      "mapping.preview": "Vista previa de solo lectura",
      "mapping.preview_help":
        "Ejecuta el mismo planificador sin crear un plan aprobable ni aplicable.",
      "mapping.preview_save_first":
        "Guarde el mapeo actual antes de ejecutar su vista previa.",
      "mapping.run_preview": "Ejecutar vista previa",
      "mapping.preview_status": "Estado",
      "mapping.preview_state.queued": "En cola",
      "mapping.preview_state.running": "Ejecutando",
      "mapping.preview_state.completed": "Completada",
      "mapping.preview_state.failed": "Falló",
      "mapping.actions": "Acciones estimadas",
      "mapping.conflicts": "Conflictos",
      "mapping.warnings": "Advertencias",
      "mapping.preview_non_applicable":
        "Una vista previa no puede aprobarse ni aplicarse. Después de guardar, genera un plan oficial desde el proyecto.",
      "mapping.coverage": "Cobertura del origen",
      "mapping.preserved": "Objetos conservados",
      "mapping.no_preview": "No se generó una vista previa para esta revisión.",
      "mapping.json_expert": "JSON Expert",
      "mapping.json_help":
        "Edita el esquema canónico en inglés. Aplicar prepara el JSON localmente; Guardar mapeo lo persiste.",
      "mapping.apply_json": "Aplicar cambios JSON",
      "mapping.discard_json": "Descartar cambios JSON",
      "mapping.catalog": "Catálogo saneado",
      "mapping.catalog_help":
        "Se muestran como máximo cinco ejemplos truncados. Los campos sensibles y snapshots completos nunca llegan al navegador.",
      "mapping.json_object": "El mapeo debe ser un objeto JSON.",
      "mapping.json_applied":
        "Cambios JSON preparados. Guarda el mapeo para persistirlos.",
      "mapping.invalid_json": "JSON no válido.",
      "mapping.unsupported_property":
        "El mapeo contiene una propiedad no compatible.",
      "mapping.invalid_type": "Esta sección del mapeo tiene un tipo no válido.",
      "mapping.invalid_object_policy": "Esta política de objeto no es válida.",
      "mapping.invalid_policy":
        "La política debe ser migrate, ignore o preserve.",
      "mapping.target_required":
        "Los objetos migrados requieren un tipo de destino en NetBox.",
      "mapping.invalid_target_type": "Tipo de destino de NetBox no compatible.",
      "mapping.invalid_rule": "La regla debe ser un objeto JSON.",
      "mapping.unsupported_rule_property":
        "La regla contiene una propiedad no compatible.",
      "mapping.invalid_rule_id":
        "El ID de la regla debe ser estable y seguro para URL.",
      "mapping.invalid_action": "La transformación del campo no es compatible.",
      "mapping.invalid_target_kind":
        "El destino debe ser un campo regular o personalizado.",
      "mapping.invalid_source_type":
        "El tipo de objeto de origen no es compatible.",
      "mapping.invalid_target":
        "El destino debe ser un nombre de campo canónico.",
      "mapping.source_field_required":
        "Esta transformación requiere un campo de origen.",
      "mapping.fields_required":
        "La concatenación requiere al menos un campo de origen.",
      "mapping.lookup_required": "Lookup requiere una tabla de conversión.",
      "mapping.duplicate_target":
        "Un tipo de origen solo puede escribir una vez en cada campo de destino.",
      "mapping.invalid_update_rule":
        "Esta regla de actualización opt-in no es válida.",
      "mapping.invalid_update_field":
        "Este campo no puede actualizarse con la regla del objeto seleccionado.",
      "mapping.invalid_preservation":
        "Esta decisión de conservación no es compatible.",
      "mapping.duplicate_rule_id":
        "Los ID de las reglas deben ser únicos en todo el mapeo.",
      "mapping.required_property":
        "Falta una propiedad obligatoria de la regla.",
      "mapping.reference_natural_key_required":
        "Las referencias deben resolverse por clave natural.",
      "mapping.invalid_reference_target":
        "El tipo de destino de la referencia en NetBox no es compatible.",
      "mapping.reference_numeric_id_forbidden":
        "Los ID numéricos de NetBox no pueden almacenarse en las reglas de mapeo.",
      "show.stale_plan":
        "Este plan está desactualizado porque cambió el descubrimiento o el mapeo. Genera un plan nuevo.",
      "show.definition_locked":
        "El descubrimiento, el mapeo y una nueva planificación permanecen bloqueados hasta reanudar y verificar la ejecución actual.",
      "show.truncated":
        "Aquí solo se muestran los primeros 500 elementos. El bundle de auditoría contiene todos los datos.",
      "show.reuse_warning":
        "{{type}} {{id}} difiere en NetBox; la reutilización conserva los valores existentes.",
      "show.schema_warning":
        "NetBox no expuso metadatos de escritura para {{endpoint}}.",
      "show.use_sandbox": "Usar el sandbox interno y desechable de NetBox",
      "show.change_target": "Planificación específica por destino",
      "show.change_target_help":
        "Después de un ensayo verificado, redescubre solo el destino NetBox y genera un nuevo plan específico para producción con el mismo origen y mapeo.",
      "show.refresh_target": "Actualizar destino NetBox",
      "conflict.target_field_constraint":
        "Un valor infringe una restricción informada por esta versión de NetBox.",
      "conflict.target_identity_collision":
        "La identidad resultante entra en conflicto con otro objeto existente en NetBox.",
      "conflict.location_classification_required":
        "Cada ubicación de phpIPAM debe clasificarse como Site o Location.",
      "conflict.location_site_required":
        "Una Location requiere un Site seleccionado explícitamente.",
      "conflict.rack_site_required":
        "Un rack requiere un Site resuelto y una Location opcional.",
      "conflict.device_prerequisites_required":
        "Al dispositivo le falta un mapeo de Site, Role, Manufacturer o Device Type.",
      "conflict.interface_type_required":
        "La interfaz requiere un tipo de interfaz de NetBox aprobado.",
      "conflict.circuit_prerequisites_required":
        "El circuito requiere referencias de Provider y Circuit Type.",
      "conflict.circuit_termination_location_required":
        "La terminación del circuito requiere una ubicación clasificada inequívoca.",
      "conflict.asn_rir_required":
        "Cada ASN requiere una referencia RIR aprobada.",
      "conflict.nat_ip_pair_required":
        "La relación NAT requiere dos direcciones IP migradas.",
      "conflict.auxiliary_creation_unapproved":
        "Un objeto auxiliar requerido no existe y su creación no fue aprobada.",
      "conflict.preserved_dependency":
        "Una dependencia requerida está configurada para conservación en lugar de migración.",
      "conflict.missing_relation_subject":
        "No se pudo resolver el objeto de la relación.",
      "conflict.invalid_field_value":
        "Una transformación de campo produjo un valor incompatible.",
      "conflict.invalid_planner_intent":
        "El planificador produjo una intención de recurso no válida.",
      "conflict.prefix_folder_preserved":
        "Una carpeta de phpIPAM no tiene equivalente seguro en NetBox y se conserva.",
      "conflict.device_ip_without_port":
        "La IP hace referencia a un dispositivo, pero no tiene puerto y queda sin asignar.",
      "conflict.pat_preserved":
        "PAT o NAT con puertos se conserva y nunca se convierte parcialmente.",
      "conflict.nat_confirmation_required":
        "El par NAT estático se conserva hasta que lo confirme un operador.",
      "conflict.primary_ip_ambiguous":
        "No se definió la IP primaria porque la correspondencia de origen es ambigua.",
      "conflict.ambiguous_target":
        "Más de un objeto de NetBox coincide con este origen.",
      "conflict.custom_field_mapping_type_conflict":
        "Las reglas de campos personalizados no coinciden en el tipo de destino.",
      "conflict.custom_field_scope_mismatch":
        "El campo personalizado existente no está asignado a todos los tipos requeridos.",
      "conflict.custom_field_type_mismatch":
        "El campo personalizado existente tiene un tipo incompatible.",
      "conflict.dependency_cycle":
        "Las dependencias de objetos contienen un ciclo.",
      "conflict.duplicate_source_identity":
        "El origen contiene una identidad de objeto duplicada.",
      "conflict.duplicate_target_claim":
        "Varios objetos de origen reclaman el mismo destino de NetBox.",
      "conflict.invalid_custom_field_value":
        "Un valor de campo personalizado no se puede convertir de forma segura.",
      "conflict.invalid_mapping": "La política de mapeo no es válida.",
      "conflict.linked_target_missing":
        "Un objeto de NetBox vinculado anteriormente ya no existe.",
      "conflict.linked_target_type_mismatch":
        "Un vínculo persistente apunta al tipo incorrecto de NetBox.",
      "conflict.missing_dependency":
        "Falta una dependencia requerida del origen.",
      "conflict.missing_identity":
        "El objeto de origen no tiene una identidad segura para migración.",
      "conflict.target_required_field_missing":
        "Falta un campo requerido por esta versión de NetBox.",
      "conflict.target_write_schema_unavailable":
        "NetBox no confirmó la compatibilidad de escritura para esta mutación.",
      "conflict.unsupported_target_choice":
        "El valor mapeado no es aceptado por esta versión de NetBox.",
      "conflict.unknown": "Conflicto bloqueante desconocido.",
      "status.draft": "Borrador",
      "status.discovering": "Descubriendo",
      "status.discovered": "Descubierto",
      "status.planning": "Planificando",
      "status.planned": "Planificado",
      "status.approved": "Aprobado",
      "status.applying": "Aplicando",
      "status.applied": "Aplicado",
      "status.verifying": "Verificando",
      "status.verified": "Verificado",
      "status.partially_applied": "Parcialmente aplicado",
      "status.failed": "Falló",
      "operation.create": "Crear",
      "operation.reuse": "Reutilizar",
      "operation.update": "Actualizar",
      "operation.ignore": "Ignorar",
      "execution.pending": "Pendiente",
      "execution.applying": "Aplicando",
      "execution.applied": "Aplicado",
      "execution.verifying": "Verificando",
      "execution.verified": "Verificado",
      "execution.verification_failed": "La verificación falló",
      "execution.failed": "Falló",
    },
  },
} as const;

const fromDocument = (): LocaleCode => {
  if (typeof document === "undefined") return "en";
  const locale = document.documentElement.lang.toLowerCase();
  return locale === "pt-br" ? "pt_BR" : locale.startsWith("es") ? "es" : "en";
};

void i18n.use(initReactI18next).init({
  resources,
  lng: fromDocument(),
  fallbackLng: "en",
  interpolation: { escapeValue: false },
});

export const isLocaleCode = (value: unknown): value is LocaleCode =>
  localeCodes.includes(value as LocaleCode);
export const setDocumentLocale = (locale: LocaleCode): void => {
  if (typeof document !== "undefined")
    document.documentElement.lang = locale === "pt_BR" ? "pt-BR" : locale;
};
export const initializeLocaleSync = (): void => {
  router.on("navigate", (event) => {
    const locale = event.detail.page.props.locale;
    if (isLocaleCode(locale)) {
      void i18n.changeLanguage(locale);
      setDocumentLocale(locale);
    }
  });
};
export const useI18n = () => {
  const { t, i18n: instance } = useTranslation();
  return {
    t,
    locale: isLocaleCode(instance.resolvedLanguage)
      ? instance.resolvedLanguage
      : ("en" as LocaleCode),
    changeLanguage: async (locale: LocaleCode) => {
      await instance.changeLanguage(locale);
      setDocumentLocale(locale);
    },
  };
};
