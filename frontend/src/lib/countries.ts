const CODES =
  'AD AE AF AG AI AL AM AO AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GT GU GW GY HK HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW'.split(' ')

const PRIORITY = ['IN', 'AE', 'GB', 'US', 'SG']

let cache: { value: string; label: string }[] | null = null

export function countryOptions(): { value: string; label: string }[] {
  if (cache) return cache
  let names: Intl.DisplayNames | null = null
  try {
    names = new Intl.DisplayNames(undefined, { type: 'region' })
  } catch {
    names = null
  }
  const label = (code: string) => names?.of(code) ?? code
  const all = CODES.map((c) => ({ value: c, label: label(c) })).sort((a, b) => a.label.localeCompare(b.label))
  const top = PRIORITY.map((c) => ({ value: c, label: label(c) }))
  cache = [...top, ...all.filter((o) => !PRIORITY.includes(o.value))]
  return cache
}

export function countryName(code: string | null | undefined): string {
  if (!code) return ''
  return countryOptions().find((o) => o.value === code)?.label ?? code
}
