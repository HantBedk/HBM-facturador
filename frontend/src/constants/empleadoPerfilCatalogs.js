/**
 * Respaldo si la API no devuelve catálogos (misma estructura que el backend).
 */
export const FALLBACK_DOCUMENT_TYPES = [
  { codigo: 'CC', nombre: 'Cédula de ciudadanía' },
  { codigo: 'CE', nombre: 'Cédula de extranjería' },
  { codigo: 'TI', nombre: 'Tarjeta de identidad' },
  { codigo: 'PASAPORTE', nombre: 'Pasaporte' },
  { codigo: 'PPT', nombre: 'Permiso por protección temporal (PPT)' },
  { codigo: 'PT', nombre: 'Permiso temporal de trabajo' },
  { codigo: 'PEP', nombre: 'Permiso especial de permanencia (PEP)' },
  { codigo: 'OTRO', nombre: 'Otro documento' },
]

export const FALLBACK_ACCOUNT_TYPES = [
  { codigo: 'ahorros', nombre: 'Cuenta de ahorros' },
  { codigo: 'corriente', nombre: 'Cuenta corriente' },
  { codigo: 'llave_breb', nombre: 'Llave Bre-B (Bre-B)' },
]

/** Subconjunto mínimo; el listado completo viene del backend (config/colombia_banks.php). */
export const FALLBACK_BANKS = [
  { codigo: 'BANCOLOMBIA', nombre: 'Bancolombia' },
  { codigo: 'BANCO_DE_BOGOTA', nombre: 'Banco de Bogotá' },
  { codigo: 'BBVA_COLOMBIA', nombre: 'BBVA Colombia' },
  { codigo: 'DAVIVIENDA', nombre: 'Davivienda' },
  { codigo: 'BANCO_POPULAR', nombre: 'Banco Popular' },
  { codigo: 'NEQUI', nombre: 'Nequi' },
  { codigo: 'DAVIPLATA', nombre: 'DaviPlata' },
]
