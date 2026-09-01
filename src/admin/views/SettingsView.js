/**
 * Site-wide design tokens.
 *
 * The layout vocabulary an author sees stays closed - Narrow, Muted, Dark -
 * but what those words resolve to belongs here. That split is what keeps pages
 * consistent without freezing the design: an editor cannot invent a width, and
 * you can change what every width means in one place.
 */

import { useState, useEffect } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Save, RotateCcw, Ruler, Palette } from 'lucide-react'
import { api } from '../../shared/api'
import { canManage } from '../../shared/settings'
import { useAsync } from '../useAsync'
import {
  Button,
  Card,
  CardBody,
  Input,
  Field,
  Alert,
  Loading,
  Spinner,
  Heading
} from '../../ui'

const GROUPS = [
  { key: 'layout', label: __('Layout', 'schemapress'), icon: Ruler },
  { key: 'colour', label: __('Colour', 'schemapress'), icon: Palette }
]

/**
 * Design token editor.
 *
 * @return {JSX.Element} The view.
 */
export function SettingsView() {
  const { data, error, loading, reload } = useAsync(() => api.settings(), [])
  const [values, setValues] = useState({})
  const [dirty, setDirty] = useState(false)
  const [saving, setSaving] = useState(false)
  const [failure, setFailure] = useState(null)

  useEffect(() => {
    if (data) {
      setValues(
        (data.tokens || []).reduce((all, token) => {
          all[token.key] = token.value

          return all
        }, {})
      )
      setDirty(false)
    }
  }, [data])

  /**
   * Persists the tokens and adopts what the server kept.
   *
   * @return {Promise<void>}
   */
  const save = async () => {
    setSaving(true)
    setFailure(null)

    try {
      const result = await api.saveSettings(values)

      setValues(
        (result.tokens || []).reduce((all, token) => {
          all[token.key] = token.value

          return all
        }, {})
      )
      setDirty(false)
      reload()
    } catch (exception) {
      setFailure(exception.message || __('Could not save settings.', 'schemapress'))
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return <Loading label={__('Loading settings…', 'schemapress')} />
  }

  const tokens = data?.tokens || []

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-end justify-between gap-4">
        <div>
          <h2 className="text-lg font-semibold tracking-tight">{__('Settings', 'schemapress')}</h2>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {__(
              'What the layout options resolve to. Delivered with the contract, so your front-end can use the same values.',
              'schemapress'
            )}
          </p>
        </div>

        {canManage ? (
          <Button disabled={!dirty || saving} onClick={save}>
            {saving ? <Spinner /> : <Save />}
            {dirty ? __('Save settings', 'schemapress') : __('Saved', 'schemapress')}
          </Button>
        ) : null}
      </div>

      {error ? <Alert variant="error">{error}</Alert> : null}
      {failure ? <Alert variant="error">{failure}</Alert> : null}

      {canManage ? null : (
        <Alert variant="info">
          {__(
            'These are site-wide and can only be changed by an administrator. You can see what the layout options resolve to, but not edit them.',
            'schemapress'
          )}
        </Alert>
      )}

      {GROUPS.map((group) => {
        const inGroup = tokens.filter((token) => token.group === group.key)

        if (inGroup.length === 0) {
          return null
        }

        return (
          <Card key={group.key}>
            <CardBody className="flex flex-col gap-4">
              <div className="flex items-center gap-2">
                <group.icon className="size-3.5 text-muted-foreground" />
                <Heading>{group.label}</Heading>
              </div>

              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {inGroup.map((token) => (
                  <Field key={token.key} label={token.label} help={token.help || undefined}>
                    {(id) => (
                      <div className="flex items-center gap-2">
                        {token.type === 'color' ? (
                          <input
                            type="color"
                            aria-label={token.label}
                            disabled={!canManage}
                            value={values[token.key] || token.default}
                            onChange={(event) => {
                              setValues({ ...values, [token.key]: event.target.value })
                              setDirty(true)
                            }}
                            className="size-9 shrink-0 cursor-pointer rounded-md border border-input bg-background p-1 disabled:cursor-not-allowed disabled:opacity-60"
                          />
                        ) : null}

                        <Input
                          id={id}
                          className="font-mono text-[12px]"
                          placeholder={token.default}
                          readOnly={!canManage}
                          value={values[token.key] ?? ''}
                          onChange={(event) => {
                            setValues({ ...values, [token.key]: event.target.value })
                            setDirty(true)
                          }}
                        />

                        {canManage && values[token.key] !== token.default ? (
                          <Button
                            size="icon-sm"
                            variant="ghost"
                            aria-label={__('Reset to default', 'schemapress')}
                            title={token.default}
                            onClick={() => {
                              setValues({ ...values, [token.key]: token.default })
                              setDirty(true)
                            }}
                          >
                            <RotateCcw />
                          </Button>
                        ) : null}
                      </div>
                    )}
                  </Field>
                ))}
              </div>
            </CardBody>
          </Card>
        )
      })}

      <Alert variant="info">
        {__(
          'Lengths take a CSS unit (rem, px, %, vw). A value that is not understood falls back to its default rather than being stored.',
          'schemapress'
        )}
      </Alert>
    </div>
  )
}
