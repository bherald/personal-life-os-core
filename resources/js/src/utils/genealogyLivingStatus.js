export function normalizeLivingStatus(living) {
  if (living === true || living === 1 || living === '1') return true
  if (living === false || living === 0 || living === '0') return false
  return null
}

export function deathStatusLabel(person) {
  if (person?.death_date) return person.death_date
  const living = normalizeLivingStatus(person?.living)
  if (living === true) return 'Living'
  if (living === false) return 'Deceased (date unknown)'
  return 'Living status unknown'
}
