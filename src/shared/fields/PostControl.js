/**
 * Post relationship field. Stores ids only, so a referenced post's title stays
 * live rather than being copied into page content at save time.
 */

import { useState, useEffect } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { X } from 'lucide-react'
import { Field, Select, Button, Badge } from '../../ui'
import { api } from '../api'

/**
 * Loads selectable posts for the configured post types.
 *
 * @param {string[]} postTypes
 * @return {Array} The available posts.
 */
function usePostOptions(postTypes) {
  const [posts, setPosts] = useState([])
  const key = (postTypes || ['page']).join(',')

  useEffect(() => {
    let cancelled = false

    api
      .posts({ types: key })
      .then((results) => {
        if (!cancelled) {
          setPosts(results)
        }
      })
      .catch(() => setPosts([]))

    return () => {
      cancelled = true
    }
  }, [key])

  return posts
}

/**
 * Single or multiple post picker.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function PostField({ field, value, onChange }) {
  const posts = usePostOptions(field.config?.post_types)
  const multiple = Boolean(field.config?.multiple)

  const options = [
    { value: '', label: __('— Select —', 'schemapress') },
    ...posts.map((post) => ({ value: String(post.id), label: post.title }))
  ]

  if (!multiple) {
    return (
      <Field label={field.label} help={field.help} required={field.required}>
        {(id) => (
          <Select
            id={id}
            value={value ? String(value) : ''}
            options={options}
            placeholder={__('— Select —', 'schemapress')}
            onChange={(next) => onChange(next === '' ? null : Number(next))}
          />
        )}
      </Field>
    )
  }

  const selected = Array.isArray(value) ? value : []

  return (
    <Field label={field.label} help={field.help} required={field.required}>
      <div className="flex flex-col gap-2">
        {selected.length > 0 ? (
          <div className="flex flex-wrap gap-1.5">
            {selected.map((id, index) => {
              const post = posts.find((candidate) => candidate.id === id)

              return (
                <Badge key={id} variant="outline" className="gap-1 py-1 pl-2 pr-1">
                  {post ? post.title : `#${id}`}
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    className="size-4"
                    aria-label={__('Remove', 'schemapress')}
                    onClick={() => onChange(selected.filter((_, i) => i !== index))}
                  >
                    <X className="size-3" />
                  </Button>
                </Badge>
              )
            })}
          </div>
        ) : null}

        <Select
          value=""
          placeholder={__('Add a post…', 'schemapress')}
          options={options.filter(
            (option) => option.value === '' || !selected.includes(Number(option.value))
          )}
          onChange={(next) => {
            if (next !== '') {
              onChange([...selected, Number(next)])
            }
          }}
        />
      </div>
    </Field>
  )
}
