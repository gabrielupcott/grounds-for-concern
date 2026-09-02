package engine

import "strings"

// MatchesCondition reports whether a transaction satisfies one condition.
func MatchesCondition(t Transaction, c Condition) bool {
	var s string
	switch c.Field {
	case "category":
		s = t.Category
	case "merchant":
		s = t.Merchant
	default:
		return false
	}

	val := strings.ToLower(c.Value)
	s = strings.ToLower(s)
	switch c.Operator {
	case "is":
		return s == val
	case "is_not":
		return s != val
	case "contains":
		return strings.Contains(s, val)
	default:
		return false
	}
}

// MatchesGroup reports whether a transaction satisfies the whole group.
// "any" is an OR over conditions, "all" is an AND.
func MatchesGroup(t Transaction, g Group) bool {
	any := g.Match == "any"
	for _, c := range g.Conditions {
		m := MatchesCondition(t, c)
		if any && m {
			return true
		}
		if !any && !m {
			return false
		}
	}
	return !any // any: nothing matched; all: nothing failed
}
