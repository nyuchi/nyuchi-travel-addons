<?php
/**
 * Reusable Elementor style controls for the trip widgets.
 *
 * Every widget here was built with its controls in the Content tab and its
 * appearance in a static stylesheet. That works until someone wants a different
 * crop on mobile, and then there is nothing to turn: the aspect ratio came from
 * a fixed data-ratio enum, object-fit was welded to `cover`, and no control was
 * registered responsively, so whatever was set applied at every width.
 *
 * These methods register the controls once and are shared by every widget, which
 * matters more than it sounds: thirteen widgets each carrying their own copy of
 * an image section is thirteen chances for them to drift apart, and a plugin
 * where the same control means something different depending on which widget
 * you opened is worse than one with no controls at all.
 *
 * Everything here uses add_responsive_control. Elementor generates the device
 * media queries from that automatically, which is what makes the tablet and
 * mobile switches in the editor do anything.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

trait WTA_Widget_Styles {

    /**
     * Controls for how an image sits inside its frame.
     *
     * The frame and the image are two different things and need separate
     * controls. The frame owns the shape - its aspect ratio and corners. The
     * image owns how it fills that shape: object-fit decides whether it is
     * cropped or letterboxed, and object-position decides which part survives
     * the crop. That second one is the control people actually reach for,
     * because `cover` on a 3:4 frame silently cuts the top off a landscape
     * photo and there is otherwise no way to say "keep the sky".
     *
     * @param string $id       Section id, unique within the widget.
     * @param string $label    Section label.
     * @param string $frame    Selector for the frame, relative to {{WRAPPER}}.
     * @param string $img      Selector for the image inside it.
     * @param array  $args     Optional: 'condition', and 'hover' to name the
     *                          element whose hover drives the zoom. Defaults to
     *                          the frame, but on a card the whole card is the
     *                          hover target - zooming only when the cursor is
     *                          over the image, while the existing stylesheet
     *                          zooms on the card, gives two different results
     *                          for what looks like one gesture.
     * @return void
     */
    protected function wta_media_style_section($id, $label, $frame, $img, $args = array()) {
        $hover = !empty($args['hover']) ? $args['hover'] : $frame;
        $section = array(
            'label' => $label,
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        );

        if (!empty($args['condition'])) {
            $section['condition'] = $args['condition'];
        }

        $this->start_controls_section($id . '_media_style', $section);

        $this->add_responsive_control($id . '_ratio', array(
            'label'       => 'Aspect ratio',
            'type'        => \Elementor\Controls_Manager::SELECT,
            'options'     => array(
                ''        => 'Default',
                '1/1'     => 'Square (1:1)',
                '4/3'     => 'Landscape (4:3)',
                '3/2'     => 'Landscape (3:2)',
                '16/9'    => 'Widescreen (16:9)',
                '3/4'     => 'Portrait (3:4)',
                '2/3'     => 'Portrait (2:3)',
                '9/16'    => 'Tall (9:16)',
                'auto'    => 'Follow the image',
            ),
            'description' => 'A card grid usually wants one fixed ratio so the rows line up. "Follow the image" lets each image keep its own shape, which suits a gallery and ruins a grid.',
            'selectors'   => array(
                '{{WRAPPER}} ' . $frame => 'aspect-ratio: {{VALUE}};',
            ),
        ));

        $this->add_responsive_control($id . '_fit', array(
            'label'       => 'How the image fills the frame',
            'type'        => \Elementor\Controls_Manager::SELECT,
            'default'     => 'cover',
            'options'     => array(
                'cover'      => 'Fill and crop (cover)',
                'contain'    => 'Fit whole image (contain)',
                'fill'       => 'Stretch to fit (fill)',
                'scale-down' => 'Fit, never enlarge',
                'none'       => 'Original size',
            ),
            'description' => 'Cover crops to fill the frame. Contain shows the whole image and leaves gaps at the sides, so give the frame a background if you use it.',
            'selectors'   => array(
                '{{WRAPPER}} ' . $img => 'object-fit: {{VALUE}};',
            ),
        ));

        $this->add_responsive_control($id . '_position', array(
            'label'       => 'Which part to keep when cropped',
            'type'        => \Elementor\Controls_Manager::SELECT,
            'default'     => 'center center',
            'options'     => array(
                'center center' => 'Centre',
                'center top'    => 'Top',
                'center bottom' => 'Bottom',
                'left center'   => 'Left',
                'right center'  => 'Right',
                'left top'      => 'Top left',
                'right top'     => 'Top right',
                'left bottom'   => 'Bottom left',
                'right bottom'  => 'Bottom right',
            ),
            'description' => 'Only does anything when the image is being cropped. On a portrait frame holding a landscape photo, "Top" keeps the sky and horizon rather than the middle of the frame.',
            'condition'   => array($id . '_fit' => array('cover', 'none')),
            'selectors'   => array(
                '{{WRAPPER}} ' . $img => 'object-position: {{VALUE}};',
            ),
        ));

        $this->add_responsive_control($id . '_radius', array(
            'label'      => 'Corner radius',
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => array('px', '%', 'em'),
            'selectors'  => array(
                '{{WRAPPER}} ' . $frame => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow: hidden;',
            ),
        ));

        $this->add_group_control(
            \Elementor\Group_Control_Css_Filter::get_type(),
            array(
                'name'     => $id . '_filter',
                'selector' => '{{WRAPPER}} ' . $img,
            )
        );

        $this->add_control($id . '_hover_zoom', array(
            'label'       => 'Zoom on hover',
            'type'        => \Elementor\Controls_Manager::SLIDER,
            'size_units'  => array('%'),
            'range'       => array('%' => array('min' => 100, 'max' => 130, 'step' => 1)),
            'default'     => array('unit' => '%', 'size' => 100),
            'description' => 'Set to 100 for no zoom. Anything above needs the frame to hide the overflow, which the corner radius control already does.',
            'selectors'   => array(
                '{{WRAPPER}} ' . $frame => 'overflow: hidden;',
                '{{WRAPPER}} ' . $img   => 'transition: transform .45s cubic-bezier(.2,.7,.3,1);',
                '{{WRAPPER}} ' . $hover . ':hover ' . $img => 'transform: scale(calc({{SIZE}} / 100));',
            ),
        ));

        $this->end_controls_section();
    }

    /**
     * Spacing, background and border for a repeating box such as a card.
     *
     * @param string $id       Section id.
     * @param string $label    Section label.
     * @param string $selector Selector for the box, relative to {{WRAPPER}}.
     * @param array  $args     Optional: 'grid' selector to expose a column gap.
     * @return void
     */
    protected function wta_box_style_section($id, $label, $selector, $args = array()) {
        $this->start_controls_section($id . '_box_style', array(
            'label' => $label,
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ));

        if (!empty($args['grid'])) {
            $this->add_responsive_control($id . '_gap', array(
                'label'      => 'Gap between items',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => array('px', 'rem'),
                'range'      => array('px' => array('min' => 0, 'max' => 80)),
                'selectors'  => array(
                    '{{WRAPPER}} ' . $args['grid'] => 'gap: {{SIZE}}{{UNIT}};',
                ),
            ));
        }

        $this->add_responsive_control($id . '_padding', array(
            'label'      => 'Padding',
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => array('px', 'em', '%'),
            'selectors'  => array(
                '{{WRAPPER}} ' . $selector => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ),
        ));

        $this->add_group_control(
            \Elementor\Group_Control_Background::get_type(),
            array(
                'name'     => $id . '_bg',
                'types'    => array('classic', 'gradient'),
                'selector' => '{{WRAPPER}} ' . $selector,
            )
        );

        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            array(
                'name'     => $id . '_border',
                'selector' => '{{WRAPPER}} ' . $selector,
            )
        );

        $this->add_responsive_control($id . '_box_radius', array(
            'label'      => 'Corner radius',
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => array('px', '%'),
            'selectors'  => array(
                '{{WRAPPER}} ' . $selector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ),
        ));

        $this->add_group_control(
            \Elementor\Group_Control_Box_Shadow::get_type(),
            array(
                'name'     => $id . '_shadow',
                'selector' => '{{WRAPPER}} ' . $selector,
            )
        );

        $this->end_controls_section();
    }

    /**
     * Typography and colour for one piece of text.
     *
     * Elementor's typography group is responsive on its own, so a heading can
     * be sized per device without anything extra here.
     *
     * @param string $id       Section id.
     * @param string $label    Section label.
     * @param array  $parts    label => selector, each getting its own controls.
     * @return void
     */
    protected function wta_text_style_section($id, $label, $parts) {
        $this->start_controls_section($id . '_text_style', array(
            'label' => $label,
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ));

        $first = true;

        foreach ($parts as $key => $part) {
            if (!$first) {
                $this->add_control($id . '_' . $key . '_divider', array(
                    'type' => \Elementor\Controls_Manager::DIVIDER,
                ));
            }

            $first = false;

            $this->add_control($id . '_' . $key . '_heading', array(
                'label'     => $part['label'],
                'type'      => \Elementor\Controls_Manager::HEADING,
                'separator' => 'none',
            ));

            $this->add_control($id . '_' . $key . '_color', array(
                'label'     => 'Colour',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} ' . $part['selector'] => 'color: {{VALUE}};',
                ),
            ));

            $this->add_group_control(
                \Elementor\Group_Control_Typography::get_type(),
                array(
                    'name'     => $id . '_' . $key . '_typography',
                    'selector' => '{{WRAPPER}} ' . $part['selector'],
                )
            );

            if (!empty($part['spacing'])) {
                $this->add_responsive_control($id . '_' . $key . '_margin', array(
                    'label'      => 'Spacing below',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => array('px', 'em'),
                    'range'      => array('px' => array('min' => 0, 'max' => 60)),
                    'selectors'  => array(
                        '{{WRAPPER}} ' . $part['selector'] => 'margin-bottom: {{SIZE}}{{UNIT}};',
                    ),
                ));
            }
        }

        $this->end_controls_section();
    }

    /**
     * Alignment, per device.
     *
     * Separate from the text section because a card that reads well centred on
     * a phone often wants to be left-aligned in a three-column desktop grid,
     * and that is the single most common thing to want to change per device.
     *
     * @param string $id       Control id.
     * @param string $selector Selector relative to {{WRAPPER}}.
     * @return void
     */
    protected function wta_align_control($id, $selector) {
        $this->add_responsive_control($id . '_align', array(
            'label'   => 'Alignment',
            'type'    => \Elementor\Controls_Manager::CHOOSE,
            'options' => array(
                'left'   => array('title' => 'Left', 'icon' => 'eicon-text-align-left'),
                'center' => array('title' => 'Centre', 'icon' => 'eicon-text-align-center'),
                'right'  => array('title' => 'Right', 'icon' => 'eicon-text-align-right'),
            ),
            'selectors' => array(
                '{{WRAPPER}} ' . $selector => 'text-align: {{VALUE}};',
            ),
        ));
    }
}
